<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

namespace DatabaseClasses;

use Database;
use MediaWiki\MediaWikiServices;
use MWException;
use RequestContext;
use Status;
use WANObjectCache;

/**
 * Class DatabaseClass
 * @package DatabaseClasses
 */
abstract class DatabaseClass extends DatabaseMetaClass {

    protected const ACTION_CREATE = 'create';
    protected const ACTION_DELETE = 'delete';
    protected const ACTION_EDIT = 'edit';


    public const ERROR_FAILEDVALIDATION = 'failedValidation';
    public const ERROR_INTERNALERROR = 'internalError';
    public const ERROR_INVALIDPROPERTY = 'invalidProperty';
    public const ERROR_NOTUNIQUE = 'notUnique';
    public const ERROR_REQUIREDMISSING = 'requiredMissing';
    public const ERROR_TYPEMISMATCH = 'typeMismatch';

    public const RELATIONSHIP_MANY_TO_MANY = 'manytomany';
    public const RELATIONSHIP_MANY_TO_ONE = 'manytoone';
    public const RELATIONSHIP_ONE_TO_MANY = 'onetomany';
    public const RELATIONSHIP_ONE_TO_ONE = 'onetoone';

    public const SOURCE_FIELD = 'field';
    public const SOURCE_PROPERTY = 'property';
    public const SOURCE_RELATIONSHIP = 'relationship';

    public const TYPE_ARRAY = 'array';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_JSON = 'json';
    public const TYPE_OBJECT = 'object';
    public const TYPE_OBJECTARRAY = 'objectarray';
    public const TYPE_STRING = 'string';
    public const TYPE_UNSIGNED_INTEGER = 'uinteger';

    protected static $schema = [];

    /**
     * @var $rightsClass
     *
     * Allows a subclass to impersonate another subclass of DatabaseClass for the purposes of user rights.
     * This is useful if a subclass should have identical rights as another class.
     */
    protected static $rightsClass = null;

    protected static $deletedCacheKeys = [];

    protected $dbExists = false;

    abstract public function __toString();


    /**
     * @param array $conds
     * @param array $options
     * @return static[]|false
     * @throws MWException
     */
    public static function getAll( array $conds = [], array $options = [] ) {
        global $wgDatabaseClassesCacheTTL, $wgDatabaseClassesDisableCache;

        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

        if( empty( $conds ) ) {
            $key = $cache->makeKey( static::class, 'all' );
        } else {
            ksort( $conds );

            foreach( $conds as $fieldName => $conditionValue ) {
                static::validateDatabaseFieldName( $fieldName );
            }

            $key = static::makeCacheKeyFromValues( $conds );
        }

        $callback = function( $oldValue, &$ttl, array &$setOpts ) use ( $conds, $options ) {
            $dbr = wfGetDB( DB_REPLICA );

            $setOpts += Database::getCacheSetOptions( $dbr );

            $tableName = static::getSchema()->getValue( 'tableName' );
            $selectParams = static::getSchema()->getValue( 'selectParams' );

            $table = $selectParams->getValue( 'table' );
            $tablePrefix = is_array( $table ) ? array_search( $tableName, $table ) . '.' : '';

            $vars = array_map( function( $fieldName ) use ( $tablePrefix ) {
                return $tablePrefix . $fieldName;
            }, static::getPrimaryKeyFields() );

            if( $tablePrefix && !empty( $conds ) ) {
                foreach( $conds as $fieldName => $conditionalValue ) {
                    $conds[ $tablePrefix . $fieldName ] = $conditionalValue;
                    unset( $conds[ $fieldName ] );
                }
            }

            $options = array_merge( $selectParams->getValue( 'options' ), $options );

            $joinConds = $selectParams->getValue( 'joinConds' );

            $dbResult = $dbr->select( $table, $vars, $conds, __METHOD__, $options, $joinConds );

            $ids = [];

            foreach( $dbResult as $dbResultObject ) {
                if( count( static::getPrimaryKeyFields() ) == 1 ) {
                    $primaryKeyFieldName = array_values( static::getPrimaryKeyFields() )[ 0 ];

                    $prepareResult = static::prepareValue( $primaryKeyFieldName, $dbResultObject->{ $primaryKeyFieldName } );

                    if( !$prepareResult->isOK() ) {
                        continue;
                    }

                    $ids[] = $prepareResult->getValue();
                } else {
                    $newId = [];

                    foreach( static::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                        $prepareResult = static::prepareValue( $primaryKeyFieldName, $dbResultObject->{ $primaryKeyFieldName } );

                        if( !$prepareResult->isOK() ) {
                            continue;
                        }

                        $newId[ $primaryKeyFieldName ] = $prepareResult->getValue();
                    }

                    if( !empty( $newId ) ) {
                        $ids[] = $newId;
                    }
                }
            }

            # If there is more than one condition or any options specified,
            # we don't want actually cache this value
            if( count( $conds ) > 1 || count( $options ) ) {
                $ttl = WANObjectCache::TTL_SECOND;
            }

            return $ids;
        };

        if( $wgDatabaseClassesDisableCache
            || in_array( $key, static::$deletedCacheKeys )
            || count( $conds ) > 1
            || count( $options ) ) {
            # If caching is disabled, just check the database directly.
            # Similarly, if the cache key corresponding to this query was deleted during this connection,
            # we are at risk of getting a stale value due to some possible race conditions and interactions with
            # tombstoning of deleted keys. Similarly, we'll just check the database directly.
            # TODO We also should never use the cache for requests with more than one condition since those cache keys
            # cannot be properly managed yet.

            # If caching is temporarily disabled, it may be optimal to delete any keys that get requested to
            # minimize stale data when it is reenabled.
            if( $wgDatabaseClassesDisableCache ) {
                $cache->delete( $key );
            }

            $ttl = null;
            $setOpts = [];

            $ids = $callback( null, $ttl, $setOpts );
        } else {
            $ids = $cache->getWithSetCallback(
                $key,
                $wgDatabaseClassesCacheTTL,
                $callback
            );
        }

        return static::getObjectsForIds( static::class, $ids );
    }


    /**
     * @param $id
     * @return false|static
     * @throws MWException
     */
    public static function getFromId( $id ) {
        # Validate $id and convert to an array if necessary
        $prepareResult = static::prepareId( $id );

        if( !$prepareResult->isOK() ) {
            return false;
        }

        $id = $prepareResult->getValue();

        return static::getFromUniqueFieldGroupValues( $id );
    }



    public static function getFromUniqueFieldGroupValues( array $uniqueFieldGroupValues ) {
        # The idea behind this function is that we can easily handle retrieval and caching for loading from several different ways.
        # $uniqueValues should be an array with keys that exactly match a group of fields defined in uniqueFields of the schema.
        # The primary key will always be a group of unique fields. This function could become the meat of retrieval, and
        # getFromId would be essentially a wrapper. Then subclasses could create very short additional wrappers if they
        # wanted to expose other retrieval methods (e.g. getFromName())

        global $wgDatabaseClassesCacheTTL, $wgDatabaseClassesDisableCache;

        $uniqueFieldNames = array_keys( $uniqueFieldGroupValues );

        # The combination of fields must exactly match a group of field names included in 'uniqueFields' in this
        # subclass's $schema
        if( !in_array( $uniqueFieldNames, static::getSchema()->getValue( 'uniqueFields' ) ) ) {
            return null;
        }

        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

        if( $uniqueFieldNames != static::getPrimaryKeyFields() ) {
            # Assume that if we're given primary key values, prepareId() has already been called.
            $prepareResult = static::prepareValues( $uniqueFieldGroupValues );

            if( !$prepareResult->isOK() ) {
                return null;
            }

            $uniqueFieldGroupValues = $prepareResult->getValue();
        }

        $key = static::makeCacheKeyFromValues( $uniqueFieldGroupValues );

        # If caching is temporarily disabled, it may be optimal to delete any keys that get requested to
        # minimize stale data when it is reenabled.
        if( $wgDatabaseClassesDisableCache ) {
            $cache->delete( $key );
        }

        static::log( 'debug', "Loading " . static::class . " object with cache key: " . $key );

        # We only want to cache the full object for the primary key. For other unique field groups, we'll cache the id
        # and then call this function again with the id. TODO this does hit the database twice, do we care?
        if( $uniqueFieldNames == static::getPrimaryKeyFields() ) {
            $callback = function( $oldValue, &$ttl, array &$setOpts ) use ( $uniqueFieldGroupValues ) {
                $dbr = wfGetDB( DB_REPLICA );

                $setOpts += Database::getCacheSetOptions( $dbr );

                $row = $dbr->selectRow( static::getSchema()->getValue( 'tableName' ), '*',
                    self::makeCaseInsensitiveSQLFragmentsForValues( $uniqueFieldGroupValues ) );

                if( !$row ) {
                    return false;
                }

                $newObject = new static();

                $newObject->dbExists = true;

                $properties = static::getProperties();

                if( !$properties ) {
                    return false;
                }

                foreach( $properties as $propertyName => $property ) {
                    if( $property->getValue( 'source' ) === self::SOURCE_FIELD && property_exists( $row, $propertyName ) ) {
                        $setValueResult = $newObject->setValue( $propertyName, $row->$propertyName );

                        if( !$setValueResult->isOK() ) {
                            return false;
                        }
                    }
                }

                if( !$newObject->loadAutoloadRelatedIds() ) {
                    return false;
                }

                return $newObject;
            };

            if( $wgDatabaseClassesDisableCache || in_array( $key, static::$deletedCacheKeys ) ) {
                # If caching is disabled, just check the database directly.
                # Similarly, if the cache key corresponding to this query was deleted during this connection,
                # we are at risk of getting a stale value due to some possible race conditions and interactions with
                # tombstoning of deleted keys. Similarly, we'll just check the database directly.

                static::log( 'debug', "Loading fresh " . static::class . " object with cache key: " . $key );

                $ttl = null;
                $setOpts = [];

                $object = $callback( null, $ttl, $setOpts );
            } else {
                static::log( 'debug', "Loading cached " . static::class . " object with cache key: " . $key );

                $object = $cache->getWithSetCallback(
                    $key,
                    $wgDatabaseClassesCacheTTL,
                    $callback
                );
            }
        } else {
            $callback = function( $oldValue, &$ttl, array &$setOpts ) use ( $uniqueFieldGroupValues ) {
                $dbr = wfGetDB( DB_REPLICA );

                $setOpts += Database::getCacheSetOptions( $dbr );

                $row = $dbr->selectRow( static::getSchema()->getValue( 'tableName' ), '*',
                    self::makeCaseInsensitiveSQLFragmentsForValues( $uniqueFieldGroupValues ) );

                if( !$row ) {
                    return false;
                }

                $id = [];

                foreach( static::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                    $id[ $primaryKeyFieldName ] = $row->$primaryKeyFieldName;
                }

                $idResult = static::prepareId( $id );

                if( !$idResult->isOK() ) {
                    return false;
                }

                return $idResult->getValue();
            };

            if( $wgDatabaseClassesDisableCache || in_array( $key, static::$deletedCacheKeys ) ) {
                # If caching is disabled, just check the database directly.
                # Similarly, if the cache key corresponding to this query was deleted during this connection,
                # we are at risk of getting a stale value due to some possible race conditions and interactions with
                # tombstoning of deleted keys. Similarly, we'll just check the database directly.

                static::log( 'debug', "Loading fresh " . static::class . " object with cache key: " . $key );

                $ttl = null;
                $setOpts = [];

                $id = $callback( null, $ttl, $setOpts );
            } else {
                static::log( 'debug', "Loading cached " . static::class . " object with cache key: " . $key );

                $id = $cache->getWithSetCallback(
                    $key,
                    $wgDatabaseClassesCacheTTL,
                    $callback
                );
            }

            if( !$id ) {
                # Not found
                return false;
            }

            $object = static::getFromUniqueFieldGroupValues( $id );
        }

        return $object;
    }

    /**
     * Returns the maximum length of a string field.
     * @param string $fieldName
     * @return false|int
     */
    public static function getMaxLength( string $fieldName ) {
        $field = static::getSchema()->getField( $fieldName );

        if( !$field
        || $field->getType() != DatabaseClass::TYPE_STRING
        || !$field->getSize() ) {
            return false;
        }

        return $field->getSize();
    }


    /**
     * @return array
     */
    public static function getPrimaryKeyFields(): array {
        return is_array( static::getSchema()->getValue( 'primaryKey' ) ) ? static::getSchema()->getValue( 'primaryKey' ) : [ static::getSchema()->getValue( 'primaryKey' ) ];
    }


    /**
     * @return DatabaseSchema
     * @throws MWException
     */
    public static function getSchema() {
        global $wgDatabaseClassesDbInfo;

        if( !static::registerSchema() ) {
            return false;
        }

        return $wgDatabaseClassesDbInfo[ static::class ][ 'schema' ];
    }


    /**
     * @param string $action
     * @return bool
     */
    public static function hasRightGeneric( string $action ): bool {
        $mwAction = static::getMediaWikiAction( $action );

        if( !$mwAction ) {
            return false;
        }

        return MediaWikiServices::getInstance()->getPermissionManager()->userHasRight(
            RequestContext::getMain()->getUser(),
            $mwAction
        );
    }


    /**
     * @param $id
     * @return bool
     * @throws MWException
     */
    public static function idExists( $id ): bool {
        # Validate $id and convert to an array if necessary
        $prepareResult = static::prepareId( $id );

        if( !$prepareResult->isOK() ) {
            return false;
        }

        $id = $prepareResult->getValue();

        if( !$id ) {
            return false;
        }

        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $key = static::makeCacheKeyFromValues( $id );

        if( $cache->get( $key ) ) {
            return true;
        }

        $dbr = wfGetDB( DB_REPLICA );

        if( $dbr->selectRow( static::getSchema()->getValue( 'tableName' ), array_keys( $id ), $id ) ) {
            return true;
        }

        return false;
    }


    public static function validateValues( array $values ) {
        $result = Status::newGood();

        $object = New static();

        $properties = static::getProperties();

        if( !$properties ) {
            $error = wfMessage(
                'databaseclasses-exception-properties-not-valid',
                static::class
            )->text();

            $result->fatal( $error, [
                'error' => DatabaseClass::ERROR_INTERNALERROR
            ] );
        }

        # Iterate through the class properties to validate and assign values to the instance
        foreach( $properties as $property ) {
            $propertyName = $property->getValue( DatabaseProperty::getIdPropertyName() );

            if( !array_key_exists( $propertyName, $values ) || is_null( $values[ $propertyName ] ) ) {
                if( $property->getValue( 'defaultValue' ) !== null ) {
                    $values[ $propertyName ] = $property->getValue( 'defaultValue' );
                }
            }

            if( !array_key_exists( $propertyName, $values ) ) {
                continue;
            }

            # Make sure value passes validation checks
            $setValueResult = $object->setValue( $propertyName, $values[ $propertyName ] );

            if( !$setValueResult->isOK() ) {
                $result->merge( $setValueResult );
            }
        }

        foreach( static::getSchema()->getValue( 'uniqueFields' ) as $uniqueFieldGroup ) {
            $uniqueFieldGroupValues = $object->getValues( $uniqueFieldGroup );

            $existingObject = static::getFromUniqueFieldGroupValues( $uniqueFieldGroupValues );

            if( $existingObject && $existingObject->getId() != $object->getId() ) {
                $params = [
                    'error' => DatabaseClass::ERROR_NOTUNIQUE,
                    'propertyName' => count( $uniqueFieldGroup ) > 1 ?  $uniqueFieldGroup : $uniqueFieldGroup[ 0 ]
                ];

                $error = wfMessage(
                    'databaseclasses-exception-object-not-unique',
                    implode( ', ', $uniqueFieldGroup ),
                    implode( ', ', $uniqueFieldGroupValues ),
                    static::class,
                    implode( ', ', $existingObject->getPrimaryKeyValues() )
                )->text();

                $result->fatal( $error, $params );
            }
        }

        return $result;
    }




    protected static function addDeletedCacheKeys( $deletedKeys ) {
        if( !is_array( $deletedKeys ) ) {
            $deletedKeys = [ $deletedKeys ];
        }

        static::$deletedCacheKeys = array_unique( array_merge( static::$deletedCacheKeys, $deletedKeys ) );
    }




    protected static function generateNewIdValue( int $idLength = 8, string $idCharacters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890' ) {
        $newId = '';

        while( !$newId ) {
            // Generate a random id
            $newId = static::generateRandomString( $idLength, $idCharacters );

            if( static::idExists( $newId ) ) {
                $newId = '';
            }
        }

        return $newId;
    }





    protected static function generateRandomString( int $length = 8, string $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890' ) {
        $randomString = '';

        for( $i = 0; $i < $length; $i++ ) {
            $randomString .= $characters[ mt_rand( 0, strlen( $characters ) - 1 ) ];
        }

        return $randomString;
    }





    protected static function getDatabaseFieldsFromProperties() {
        $fields = [];

        if( !static::registerProperties() ) {
            return false;
        }

        foreach( static::$properties as $propertyData ) {
            if( $propertyData[ 'source' ] !== self::SOURCE_FIELD ) {
                continue;
            }

            $propertyField = DatabaseField::newFromValues( $propertyData );

            if( !$propertyField ) {
                return false;
            }

            $fields[ $propertyData[ DatabaseProperty::getIdPropertyName() ] ] = $propertyField;
        }

        return $fields;
    }



    protected static function getMediaWikiAction( string $action ) {
        global $wgAvailableRights;

        $actionRightsClass = static::class;

        if( static::$rightsClass && DatabaseClass::validateSubclass( static::$rightsClass ) ) {
            $actionRightsClass = static::$rightsClass;
        }

        $mediaWikiAction = str_replace('\\', '-', strtolower( $actionRightsClass ) ) . '-' . $action;

        if( !in_array( $mediaWikiAction, $wgAvailableRights ) ) {
            static::handleError( wfMessage(
                'databaseclasses-exception-right-not-defined',
                $mediaWikiAction
            )->text() );

            return false;
        }

        return $mediaWikiAction;
    }





    protected static function getObjectsForIds( string $objectClass, array $ids ) {
        if( !DatabaseClass::validateSubclass( $objectClass ) || is_null( $ids ) ) {
            return false;
        }

        $objects = [];

        foreach( $ids as $id ) {
            $object = $objectClass::getFromId( $id );

            if( $object ) {
                if( is_array( $id ) ) {
                    $id = serialize( $id );
                }

                $objects[ $id ] = $object;
            } else {
                // TODO This shouldn't ever happen, consider logging to catch this if suspected.
            }
        }

        return $objects;
    }




    protected static function makeCacheKeyFromValues( array $values ) {
        ksort( $values );

        return MediaWikiServices::getInstance()->getMainWANObjectCache()->makeKey(
            static::class,
            serialize( $values )
        );
    }




    protected static function prepareId( $id ): Status {
        $result = Status::newGood();

        $primaryKeyFields = static::getPrimaryKeyFields();

        if( count( $primaryKeyFields ) == 1 ) {
            if( is_scalar( $id ) ) {
                # Convert $id to an array
                $id = [
                    reset( $primaryKeyFields ) => $id
                ];
            } elseif( is_array( $id ) ) {
                if( count( $id ) != 1
                    || key( $id ) != reset( $primaryKeyFields )
                    || in_array( false, array_map( function( $val ) {
                        return is_scalar( $val ) || is_null( $val );
                    }, $id ) ) ) {

                    $error = wfMessage(
                        'databaseclasses-exception-invalid-id-value-array',
                        var_export( $id ),
                        static::class
                    )->text();

                    static::handleError( $error );
                    $result->fatal( $error );

                    return $result;
                }
            } else {
                $error = wfMessage(
                    'databaseclasses-exception-invalid',
                    'ID ' . strval( $id )
                )->text();

                static::handleError( $error );
                $result->fatal( $error );

                return $result;
            }
        } else {
            if( !is_array( $id ) ) {
                $error = wfMessage(
                    'databaseclasses-exception-id-value-must-be-array',
                    static::class,
                    implode( $primaryKeyFields ),
                    $id
                )->text();

                static::handleError( $error );
                $result->fatal( $error );

                return $result;
            }

            $missingPrimaryKeyValues = array_diff( $primaryKeyFields, array_keys( $id ) );
            $invalidPrimaryKeyValues = array_diff( array_keys( $id ), $primaryKeyFields );

            if( !empty( $missingPrimaryKeyValues ) ) {
                $error = wfMessage(
                    'databaseclasses-exception-primary-key-field-value-missing',
                    implode( $missingPrimaryKeyValues ),
                    implode( $id ),
                    static::class
                )->text();

                static::handleError( $error );
                $result->fatal( $error );

                return $result;
            } elseif( !empty( $invalidPrimaryKeyValues ) ) {
                $error = wfMessage(
                    'databaseclasses-exception-primary-key-field-invalid',
                    implode( $invalidPrimaryKeyValues ),
                    implode( $id ),
                    static::class
                )->text();

                static::handleError( $error );
                $result->fatal( $error );

                return $result;
            }
        }

        $preparedId = [];

        foreach( $primaryKeyFields as $primaryKeyFieldName ) {
            $property = static::getProperty( $primaryKeyFieldName );

            if( !$property ) {
                # TODO clean this up
                $result->fatal( '' );

                return $result;
            }

            $propertyType = $property->getValue( 'type' );

            if( $propertyType ) {
                $typeMismatch = false;

                # Special case for unsigned integers, we have to change the variable passed to settype()
                # to integer and then specifically check that it's not negative after.
                if( $propertyType === DatabaseClass::TYPE_UNSIGNED_INTEGER ) {
                    $propertyType = DatabaseClass::TYPE_INTEGER;
                }

                if( !settype( $id[ $primaryKeyFieldName ], $propertyType ) ) {
                    $typeMismatch = true;
                } elseif( $property->getValue( 'type' ) === DatabaseClass::TYPE_UNSIGNED_INTEGER && $id[ $primaryKeyFieldName ] < 0 ) {
                    $typeMismatch = true;

                    # Set property type back to the correct value for error handling
                    $propertyType = DatabaseClass::TYPE_UNSIGNED_INTEGER;
                }

                if( $typeMismatch ) {
                    $error = wfMessage(
                        'databaseclasses-exception-type-mismatch',
                        $primaryKeyFieldName,
                        static::class,
                        $propertyType,
                        gettype( $id[ $primaryKeyFieldName ] )
                    )->text();

                    static::handleError( $error );
                    $result->fatal( $error );

                    return $result;
                }
            }

            $preparedId[ $primaryKeyFieldName ] = $id[ $primaryKeyFieldName ];
        }

        $result->setResult( true, $preparedId );

        return $result;
    }




    protected static function prepareValue( string $propertyName, $value ): Status {
        $result = parent::prepareValue( $propertyName, $value );

        if( !$result->isOK() ) {
            return $result;
        }

        $value = $result->getValue();

        # Unset value for now
        $result->setResult( true, null );

        # If the property is a relationship to a subclass of DatabaseClass and the value is set (truthy),
        # Make sure the referenced object actually exists.
        $property = static::getProperty( $propertyName );

        if( !$property ) {
            $error = wfMessage(
                'databaseclasses-exception-invalid-property-for-class',
                $propertyName,
                static::class
            )->text();

            $result->fatal( $error, [
                'error' => DatabaseClass::ERROR_INTERNALERROR,
                'propertyName' => $propertyName
            ] );

            return $result;
        }

        $relationship = static::getSchema()->getRelationship( $propertyName );

        if( $relationship && $value ) {
            $relatedClassName = $relationship->getValue( 'relatedClassName' );

            if( DatabaseClass::hasSubclass( $relatedClassName ) ) {
                $arrValue = [];

                if( !is_array( $value ) ) {
                    $arrValue[] = &$value;
                } else {
                    $arrValue = &$value;
                }

                foreach( $arrValue as $key => $iValue ) {
                    if( $property->getValue( 'source' ) === self::SOURCE_RELATIONSHIP ) {
                        $prepareResult = $relatedClassName::prepareId( $iValue );

                        if( !$prepareResult->isOK() ) {
                            return $prepareResult;
                        }

                        $arrValue[ $key ] = $prepareResult->getValue();
                    }

                    if( !$relatedClassName::idExists( $iValue ) ) {
                        $error = wfMessage(
                            'databaseclasses-exception-object-does-not-exist',
                            $relatedClassName,
                            implode( $relatedClassName::getPrimaryKeyFields() ),
                            $iValue
                        )->text();

                        static::handleError( $error );

                        $result->fatal( $error, [
                            'error' => DatabaseClass::ERROR_FAILEDVALIDATION,
                            'propertyName' => $propertyName
                        ] );

                        return $result;
                    }
                }
            }
        }

        $result->setResult( true, $value );

        return $result;
    }




    protected static function prepareValues( array $propertyValues ): Status {
        $result = Status::newGood();

        foreach( $propertyValues as $propertyName => $propertyValue ) {
            $prepareResult = static::prepareValue( $propertyName, $propertyValue );

            if( !$prepareResult->isOK() ) {
                return $prepareResult;
            }

            $propertyValues[ $propertyName ] = $prepareResult->getValue();
        }

        $result->setResult( true, $propertyValues );

        return $result;
    }




    protected static function registerSchema() {
        global $wgDatabaseClassesDbInfo;

        if( !isset( $wgDatabaseClassesDbInfo[ static::class ][ 'schema' ] ) ) {
            if( empty( static::$schema ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-schema-not-defined-for-class',
                    static::class
                )->text() );
            }

            $schemaValues = static::$schema;

            $schemaValues[ 'className' ] = static::class;
            $schemaValues[ 'fields' ] = static::getDatabaseFieldsFromProperties();

            if( !isset( $schemaValues[ 'selectParams' ] ) ) {
                $schemaValues[ 'selectParams' ] = [
                    'table' => $schemaValues[ 'tableName' ],
                ];

                if( isset( $schemaValues[ 'orderBy' ] ) ) {
                    $schemaValues[ 'selectParams' ][ 'options' ] = [
                        'ORDER BY' => $schemaValues[ 'orderBy' ]
                    ];
                }
            }

            $schema = DatabaseSchema::newFromValues( $schemaValues );

            if( !$schema ) {
                return false;
            }

            foreach( $schema->getValue( 'relationships' ) as $relationship ) {
                $relationshipType = $relationship->getValue( 'relationshipType' );
                $propertyName = $relationship->getValue( 'propertyName' );

                $propertyNames = is_array( $propertyName ) ? $propertyName : [ $propertyName ];

                foreach( $propertyNames as $propertyName ) {
                    $property = static::getProperty( $propertyName );

                    if( !$property ) {
                        throw new MWException( wfMessage(
                            'databaseclasses-exception-relationship-propertyname-not-defined-in-properties',
                            $propertyName,
                            static::class
                        )->text() );
                    }

                    if( $relationshipType === DatabaseClass::RELATIONSHIP_MANY_TO_MANY || $relationshipType === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
                        if( $property->getValue( 'source' ) !== self::SOURCE_RELATIONSHIP ) {
                            throw new MWException( wfMessage(
                                'databaseclasses-exception-relationship-not-dbrelationship',
                                $propertyName,
                                static::class,
                                $relationship->getValue( static::getIdPropertyName() )
                            )->text() );
                        }
                    }

                    if( $relationshipType === DatabaseClass::RELATIONSHIP_ONE_TO_MANY || $relationshipType === DatabaseClass::RELATIONSHIP_ONE_TO_ONE ) {
                        if( $property->getValue( 'source' ) !== self::SOURCE_FIELD ) {
                            throw new MWException( wfMessage(
                                'databaseclasses-exception-relationship-not-dbfield',
                                $propertyName,
                                static::class,
                                $relationship->getValue( static::getIdPropertyName() )
                            )->text() );
                        }
                    }
                }
            }

            $wgDatabaseClassesDbInfo[ static::class ][ 'schema' ] = $schema;
        }

        return true;
    }



    protected static function validateDatabaseFieldName( string $fieldName ): bool {
        if( !static::getSchema()->getField( $fieldName ) ) {
            throw new MWException( wfMessage(
                'databaseclasses-exception-invalid-database-field-for-class',
                $fieldName,
                static::class
            )->text() );
        }

        return true;
    }


    /**
     * MediaWiki uses binary collation by default which is case-sensitive. However, case insensitivity is often desirable
     * when checking for uniqueness. This function takes an array with [ fieldName => value ] and converts it to
     * [ N => 'convert(fieldName using utf8mb4) = value' ] which makes the comparison case insensitive.
     *
     * @param array $values
     * @return array
     */
    private static function makeCaseInsensitiveSQLFragmentsForValues( array $values ): array {
        $sqlFragments = [];

        $dbr = wfGetDB( DB_REPLICA );

        foreach( $values as $fieldName => $value ) {
            $sqlFragments[] = 'CONVERT(' . $dbr->addIdentifierQuotes( $fieldName ) . ' USING utf8mb4) = ' . $dbr->addQuotes( $value );
        }

        return $sqlFragments;
    }


    /**
     * @return Status
     */
    public function canDelete(): Status {
        return $this->delete( true );
    }


    /**
     * @return Status
     */
    public function canSave(): Status {
        return $this->save( true );
    }


    /**
     * @param bool $test
     * @return Status
     * @throws MWException
     */
    public function delete( bool $test = false ): Status {
        $result = Status::newGood();

        # Check permissions
        $action = static::ACTION_DELETE;

        $permissionsResult = $this->hasRight( $action );

        if( !$permissionsResult->isOK() ) {
            return $permissionsResult;
        }

        if( !$this->isPrimaryKeySet() ) {
            $result->fatal(
                'databaseclasses-exception-required-fields-not-defined',
                static::class,
                implode( static::getPrimaryKeyFields() )
            );

            return $result;
        }

        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

        $dbw = wfGetDB( DB_MASTER );

        $tableName = static::getSchema()->getValue( 'tableName' );
        $conds = $this->getPrimaryKeyValues();

        # Make sure the object exists in the database
        $dbObject = static::getFromId( $this->getPrimaryKeyValues() );

        if( !$dbObject ) {
            $result->fatal(
                'databaseclasses-exception-object-does-not-exist',
                static::class,
                implode( static::getPrimaryKeyFields() ),
                implode( $this->getPrimaryKeyValues() )
            );

            return $result;
        }

        # Initalize array of cache keys to purge
        $cacheKeysToDelete = [
            $cache->makeKey( static::class, 'all' )
        ];

        # Get the cache keys to delete for this object
        $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $this->getCacheKeys() );

        # Initialize related arrays requiring database operations after deletion
        $relatedObjectsToDelete = [];
        $relatedObjectsToEdit = [];
        $relatedJunctionTables = [];

        foreach( static::getSchema()->getValue( 'relationships' ) as $relationship ) {
            $propertyName = $relationship->getValue( 'propertyName' );
            $relatedClassName = $relationship->getValue( 'relatedClassName' );
            $relatedPropertyName = $relationship->getValue( 'relatedPropertyName' );

            if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_ONE_TO_MANY ) {
                # One-to-many relationships should invalidate the cache for this same class for any queries searching
                # for the relationship property name with the value matching this object
                $cacheKeysToDelete[] = static::makeCacheKeyFromValues( $this->getValues( $propertyName ) );

                # If the related class autoloads the related property array, purge the cache for the related class with
                # the id used by this object.
                if( DatabaseClass::hasSubclass( $relatedClassName ) && $relatedPropertyName ) {
                    $relatedPropertyAutoload = $relatedClassName::getSchema()->getRelationship( $relatedPropertyName )->getValue( 'autoload' );

                    if( $relatedPropertyAutoload ) {
                        $relatedObject = $relatedClassName::getFromId( $this->getValues( $propertyName ) );

                        if( $relatedObject ) {
                            $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $relatedObject->getCacheKeys() );
                        }
                    }
                }
            } elseif( DatabaseClass::hasSubclass( $relatedClassName ) && $relatedPropertyName ) {
                if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_MANY ) {
                    # Many-to-many relationships should delete rows from the junction table that match this object.
                    $junctionTableName = $relationship->getValue( 'junctionTableName' );

                    # Make sure the junction table is accessible
                    try {
                        $dbw->selectRow( $junctionTableName, $this->getPrimaryKeyFields(), '*' );
                    } catch( MWException $e ) {
                        return Status::newFatal( $e->getMessage() );
                    }

                    $relatedJunctionTables[] = $junctionTableName;

                    # If the related class autoloads the related property, the cache for related objects should also be purged.
                    if( $relatedClassName::getSchema()->getRelationship( $relatedPropertyName )->getValue( 'autoload' ) ) {
                        $relatedObjects = static::getObjectsForIds( $relatedClassName, $this->getValue( $relationship->getValue( 'propertyName' ) ) );

                        foreach( $relatedObjects as $relatedObject ) {
                            $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $relatedObject->getCacheKeys() );
                        }
                    }
                } elseif( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
                    # If the related property is required, related objects for a one-to-many relationship should be deleted
                    # from the database. Otherwise, they should be updated with the related property value set to null.
                    # These operations will handle their own cache updating, so we don't need to do anything for that here.
                    $relatedPropertyRequired = $relatedClassName::getSchema()->getField( $relatedPropertyName )->getValue( 'required' );

                    $relatedObjects = static::getObjectsForIds( $relatedClassName, $this->getValue( $relationship->getValue( 'propertyName' ) ) );

                    foreach( $relatedObjects as $relatedObject ) {
                        if( $relatedPropertyRequired ) {
                            # Only test deletion here
                            $result = $relatedObject->delete( true );
                        } else {
                            $setValueResult = $relatedObject->setValue( $relatedPropertyName, null );

                            if( !$setValueResult->isOK() ) {
                                return $setValueResult;
                            }

                            # Only test saving here
                            $result = $relatedObject->save( true );
                        }

                        if( !$result->isOK() ) {
                            return $result;
                        }
                    }
                }
            }
        }

        if( !$test ) {
            $dbw->startAtomic( __METHOD__ );

            $dbw->delete( $tableName, $conds );

            # Verify delete
            if( $dbw->affectedRows() !== 1 ) {
                $result->fatal(
                    'databaseclasses-exception-delete-failed',
                    $tableName,
                    implode( static::getPrimaryKeyFields() ),
                    implode( $this->getPrimaryKeyValues() ),
                    $dbw->lastError()
                );

                return $result;
            }
        }

        # Delete related objects
        foreach( $relatedObjectsToDelete as $relatedObject ) {
            $result = $relatedObject->delete( $test );

            if( !$result->isOK() ) {
                return $result;
            }
        }

        # Update related objects
        foreach( $relatedObjectsToEdit as $relatedObject ) {
            $result = $relatedObject->save( $test );

            if( !$result->isOK() ) {
                return $result;
            }
        }

        if( !$test ) {
            # Delete related rows from junction tables
            foreach( $relatedJunctionTables as $junctionTable ) {
                $dbw->delete( $junctionTable, $conds );

                # TODO verify?
            }

            # Delete cache keys
            static::addDeletedCacheKeys( $cacheKeysToDelete );

            $dbw->onTransactionPreCommitOrIdle( function() use ( $cache, $cacheKeysToDelete ) {
                static::log( 'debug', "Delete complete. Deleting cache keys: \n" . implode( "\n", $cacheKeysToDelete ) );

                foreach( $cacheKeysToDelete as $key ) {
                    $cache->delete( $key );
                }
            } );

            $dbw->endAtomic(__METHOD__);

            $this->dbExists = false;

            # Unset the primary key if it uses autoincrement
            foreach( static::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                if( static::getSchema()->getField( $primaryKeyFieldName )->getValue( 'autoincrement' ) ) {
                    $this->setValue( $primaryKeyFieldName, null );
                }
            }
        }

        return $result;
    }


    /**
     * @return bool
     * @throws MWException
     */
    public function exists(): bool {
        if( $this->dbExists ) {
            return true;
        }

        # If the id property is defined, try to load it from the database (need to do it statically to not overwrite
        # values in this instance.
        if( $this->isPrimaryKeySet() && static::idExists( $this->getPrimaryKeyValues() ) ) {
            $this->dbExists = true;

            return true;
        }

        return false;
    }


    /**
     * @return array
     */
    public function getPrimaryKeyValues(): array {
        return $this->getValue( static::getPrimaryKeyFields() );
    }


    /**
     * @param $propertyName
     * @return array|mixed
     */
    public function getValue( $propertyName ) {
        if( is_array( $propertyName ) ) {
            return $this->getValues( $propertyName );
        }

        static::validatePropertyName( $propertyName );

        $property = static::getProperty( $propertyName );

        if( $this->isValueSet( $propertyName ) ) {
            return $this->values[ $propertyName ];
        }
        elseif( $property->getValue( 'source' ) === self::SOURCE_RELATIONSHIP ) {
            return $this->getRelationshipValue( $propertyName );
        } else {
            return static::getDefaultValue( $propertyName );
        }
    }


    /**
     * @return array|mixed
     * @throws MWException
     */
    public function getId() {
        if( count( static::getPrimaryKeyFields() ) == 1 ) {
            return $this->getValue( array_values( static::getPrimaryKeyFields() )[ 0 ] );
        } else {
            return $this->getPrimaryKeyValues();
        }
    }


    /**
     * @param string $action
     * @return Status
     * @throws MWException
     */
    public function hasRight( string $action ): Status {
        $result = Status::newGood();

        if( !static::hasRightGeneric( $action ) ) {
            $result->fatal(
                'databaseclasses-exception-permission-denied',
                RequestContext::getMain()->getUser()->getName(),
                $action,
                static::class,
                static::getMediaWikiAction( $action )
            );
        }

        return $result;
    }


    /**
     * @param bool $test
     * @return Status
     * @throws MWException
     */
    public function save( bool $test = false ): Status {
        $result = Status::newGood();

        $dbObject = static::getFromId( $this->getPrimaryKeyValues() );

        # Check permissions
        if( !$dbObject ) {
            $action = static::ACTION_CREATE;
        } else {
            $action = static::ACTION_EDIT;
        }

        $permissionsResult = $this->hasRight( $action );

        if( !$permissionsResult->isOK() ) {
            return $permissionsResult;
        }

        # Make sure all required fields are defined to a valid value
        foreach( static::getSchema()->getAllFields() as $field ) {
            $fieldName = $field->getValue( 'name' );
            $property = static::getProperty( $fieldName );

            if( $property->isRequired() && !$this->getValue( $fieldName ) ) {
                $result->fatal(
                    'databaseclasses-exception-required-fields-not-defined',
                    static::class,
                    $fieldName
                );

                return $result;
            }
        }

        $dbw = wfGetDB( DB_MASTER );
        $tableName = static::getSchema()->getValue( 'tableName' );

        $dbFieldNames = array_keys( static::getSchema()->getValue( 'fields' ) );

        # Make sure unique field groups don't already exist in the database or are associated with this object if editing
        $uniqueFields = static::getSchema()->getValue( 'uniqueFields' );

        if( $uniqueFields ) {
            foreach( $uniqueFields as $uniqueFieldGroup ) {
                # getFields will return an associative array with the field names as keys and the values as values which
                # is the same structure expected for $conds by selectRow()
                $conds = $this->getValue( $uniqueFieldGroup );

                $row = $dbw->selectRow( $tableName, static::getPrimaryKeyFields(), $conds );

                if( $row ) {
                    foreach( static::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                        if( $row->$primaryKeyFieldName != $this->getValue( $primaryKeyFieldName ) ) {
                            $result->fatal(
                                'databaseclasses-exception-object-not-unique',
                                implode( ', ', array_keys( $conds ) ),
                                implode( ', ', $conds ),
                                static::class,
                                implode( $this->getPrimaryKeyValues() )
                            );

                            return $result;
                        }
                    }
                }
            }
        }

        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

        # Initalize array of cache keys to purge
        $cacheKeysToDelete = [
            $cache->makeKey( static::class, 'all' )
        ];

        # Get all the relevant cache keys for this object that should be deleted
        $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $this->getCacheKeys() );

        # Try to load the object with these primary key values. If it doesn't exist (i.e. a new object to be added),
        # this will be false. We will use this to compare values that have changed to handle any relevant cache management.
        $dbObject = static::getFromId( $this->getPrimaryKeyValues() );

        if( !$test ) {
            $dbw->startAtomic( __METHOD__ );
        }

        # Determine whether to insert new or update existing row
        if( !$dbObject ) {
            # If an id isn't already specified and the id field doesn't use autoincrement, allow subclass to
            # generate an id value
            if( !$this->isPrimaryKeySet() ) {
                foreach( $this->getPrimaryKeyValues() as $primaryKeyFieldName => $primaryKeyValue ) {
                    if( !$primaryKeyValue && !static::getSchema()->getField( $primaryKeyFieldName )->getValue( 'autoincrement' ) ) {
                        $newIdValue = static::generateNewIdValue();

                        if( !$newIdValue ) {
                            $result->fatal(
                                'databaseclasses-exception-nonautoincrement-id-field-not-defined',
                                static::class
                            );

                            return $result;
                        }

                        $setValueResult = $this->setValue( $primaryKeyFieldName, $newIdValue );

                        if( !$setValueResult->isOK() ) {
                            return $setValueResult;
                        }

                        # If just testing, revert to the original value
                        if( $test ) {
                            $this->setValue( $primaryKeyFieldName, $primaryKeyValue );
                        }
                    }
                }
            }

            if( !$test ) {
                # Insert new row into table
                $dbw->insert( $tableName, $this->getValues( $dbFieldNames ) );

                # Verify insertion
                if( $dbw->affectedRows() !== 1 ) {
                    $result->fatal(
                        'databaseclasses-exception-insert-failed',
                        $tableName,
                        $dbw->lastError()
                    );

                    return $result;
                }

                # If the table uses an autoincrement field for the primary key, set that value
                foreach( static::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                    if( static::getSchema()->getField( $primaryKeyFieldName )->getValue( 'autoincrement' ) ) {
                        $this->setValue( $primaryKeyFieldName, $dbw->insertId() );
                    }
                }

                $this->dbExists = true;
            }
        } else {
            # Get all the relevant cache keys to delete for the existing (i.e. previous) object
            $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $dbObject->getCacheKeys() );

            if( !$test ) {
                # Update existing row in table
                $dbw->update(
                    $tableName,
                    $this->getValues( $dbFieldNames ),
                    $this->getPrimaryKeyValues()
                );

                # Verify update
                if( $dbw->affectedRows() !== 1 ) {
                    $result->fatal(
                        'databaseclasses-exception-update-failed',
                        $tableName,
                        implode( static::getPrimaryKeyFields() ),
                        implode( $this->getPrimaryKeyValues() ),
                        $dbw->lastError()
                    );

                    return $result;
                }
            }
        }

        foreach( static::getSchema()->getValue( 'relationships' ) as $relationship ) {
            # Could be a single property name as a string or an array of property names
            $propertyName = $relationship->getValue( 'propertyName' );

            # If the value hasn't been set, there's nothing to do
            if( !$this->areValuesSet( $propertyName ) ) {
                continue;
            }

            # Get values for property name(s). These will be arrays if propertyName is an array, and scalars otherwise
            $propertyValue = $this->getValue( $propertyName );
            $previousPropertyValue = $dbObject ? $dbObject->getValue( $propertyName ) : null;

            # If the value is changed (or new), adjustments may need to be made to the cache
            if( $propertyValue != $previousPropertyValue ) {
                $relatedClassName = $relationship->getValue( 'relatedClassName' );
                $relatedPropertyName = $relationship->getValue( 'relatedPropertyName' );
                $relatedPropertyAutoload = $relatedPropertyName ? $relatedClassName::getSchema()->getRelationship( $relatedPropertyName )->getValue( 'autoload' ) : false;

                if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_ONE_TO_MANY ) {
                    # For one-to-many relationships, the property name for this class will correspond to one or more
                    # database fields representing an id for a related class.

                    # Property name could be an array. Regardless, get values as arrays
                    $propertyValues = $this->getValues( $propertyName );
                    $previousPropertyValues = $dbObject ? $dbObject->getValues( $propertyName ) : array_fill( 0, count( $propertyValues ), null );

                    $cacheKeysToDelete[] = static::makeCacheKeyFromValues( $propertyValues );
                    $cacheKeysToDelete[] = static::makeCacheKeyFromValues( $previousPropertyValues );

                    # If the related property autoloads the array of ids for this class, clear the related instance's cache.
                    if( DatabaseClass::hasSubclass( $relatedClassName ) && $relatedPropertyAutoload ) {
                        $relatedObject = $relatedClassName::getFromId( $propertyValues );
                        $previousRelatedObject = $relatedClassName::getFromId( $previousPropertyValues );

                        $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $relatedObject->getCacheKeys() );

                        if( $previousRelatedObject ) {
                            $cacheKeysToDelete = array_merge( $cacheKeysToDelete, $previousRelatedObject->getCacheKeys() );
                        }
                    }
                } elseif( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_MANY || $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
                    # For either many-to-many or many-to-one, propertyName must be a scalar string.
                    # propertyValue will be an array of already prepared and validated ids.
                    $previousRelatedIds = $previousPropertyValue ? $previousPropertyValue : [];

                    $relatedIdsToAdd = array_filter( $propertyValue, function( $val ) use ( $previousRelatedIds ) {
                        return !in_array( $val, $previousRelatedIds );
                    } );

                    $relatedIdsToRemove = array_filter( $previousRelatedIds, function( $val ) use ( $propertyValue ) {
                        return !in_array( $val, $propertyValue );
                    } );

                    $relatedIdsToChange = array_merge( $relatedIdsToAdd, $relatedIdsToRemove );

                    if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_MANY ) {
                        # We only have to worry about caching here for many-to-many relationships, since updating objects
                        # for many-to-one relationships will take care of their own cache. Additionally, purging the cache
                        # is only necessary if the related property autoloads the relationship.
                        if( $relatedPropertyAutoload ) {
                            # Only delete the cache key for the related ids that are changing.
                            foreach( $relatedIdsToChange as $changedRelatedId ) {
                                $cacheKeysToDelete[] = $relatedClassName::makeCacheKeyFromValues( $changedRelatedId );
                            }
                        }

                        $junctionTableName = $relationship->getValue( 'junctionTableName' );

                        # Make sure the junction table is accessible
                        try {
                            $dbw->selectRow( $junctionTableName, $this->getPrimaryKeyFields(), '*' );
                        } catch( MWException $e ) {
                            return Status::newFatal( $e->getMessage() );
                        }

                        if( !$test ) {
                            foreach( $relatedIdsToChange as $relatedId ) {
                                if( in_array( $relatedId, $relatedIdsToAdd ) ) {
                                    $dbw->insert( $junctionTableName, array_merge( $this->getPrimaryKeyValues(), $relatedId ) );

                                    $failedMessage = 'databaseclasses-exception-insert-failed';
                                } else {
                                    $dbw->delete( $junctionTableName, array_merge( $this->getPrimaryKeyValues(), $relatedId ) );

                                    $failedMessage = 'databaseclasses-exception-delete-failed';
                                }

                                # Verify result
                                if( $dbw->affectedRows() !== 1 ) {
                                    $result->fatal(
                                        $failedMessage,
                                        $junctionTableName,
                                        $dbw->lastError()
                                    );

                                    return $result;
                                }
                            }
                        }
                    } elseif( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
                        # For many-to-one relationships, the related property name might be an array of property names.
                        $relatedPropertyNames = is_array( $relatedPropertyName ) ? $relatedPropertyName : [ $relatedPropertyName ];

                        foreach( $relatedIdsToChange as $relatedId ) {
                            $relatedObject = $relatedClassName::getFromId( $relatedId );

                            # TODO this assumes that the primary key fields of the related class will map to the property names of this relationship in the same order. This is not intuitive nor asserted.
                            $newValues = array_combine( $relatedPropertyNames,
                                in_array( $relatedId, $relatedIdsToAdd ) ? $this->getPrimaryKeyValues() : array_fill( 0, count( $relatedPropertyNames ), null ) );

                            if( !$test || $dbObject ) {
                                $setValueResult = $relatedObject->setValues( $newValues );

                                if( !$setValueResult->isOK() ) {
                                    return $setValueResult;
                                }

                                $result = $relatedObject->save( $test );

                                if( !$result->isOK() ) {
                                    return $result;
                                }
                            } else {
                                # TODO we can't really test setting/saving of the related object if this instance doesn't
                                # exist yet.
                                $permissionsResult = $relatedObject->hasRight( static::ACTION_EDIT );

                                if( !$permissionsResult->isOK() ) {
                                    static::log( 'debug', "Loading " . static::class . " object with cache key: " . $key );

                                    return $permissionsResult;
                                }
                            }
                        }
                    }
                }
            }
        }

        if( !$test ) {
            $cacheKeysToDelete = array_unique( $cacheKeysToDelete );

            # Delete cache keys
            static::addDeletedCacheKeys( $cacheKeysToDelete );

            $dbw->onTransactionPreCommitOrIdle( function() use ( $cache, $cacheKeysToDelete ) {
                static::log( 'debug', "Save complete. Deleting cache keys: \n" . implode( "\n", $cacheKeysToDelete ) );

                foreach( $cacheKeysToDelete as $key ) {
                    $cache->delete( $key );
                }
            } );

            $dbw->endAtomic( __METHOD__ );
        }

        return $result;
    }



    protected function getCacheKeys(): array {
        $cacheKeys = [];

        # Get cache keys related to the unique field group values of the object
        foreach( static::getSchema()->getValue( 'uniqueFields' ) as $uniqueFieldGroup ) {
            $cacheKeys[] = static::makeCacheKeyFromValues( $this->getValues( $uniqueFieldGroup ) );
        }

        # Get cache keys for each field and value
        foreach( static::getSchema()->getAllFields() as $field ) {
            $cacheKeys[] = static::makeCacheKeyFromValues( $this->getValues( $field->getValue( 'name' ) ) );
        }

        return $cacheKeys;
    }




    protected function getRelationshipValue( string $relationshipPropertyName ) {
        $relationships = static::getSchema()->getValue( 'relationships' );

        if( !isset( $relationships[ $relationshipPropertyName ] ) ) {
            throw new MWException( wfMessage(
                'databaseclasses-exception-invalid-relationship-for-class',
                $relationshipPropertyName,
                static::class
            )->text() );
        }

        $relationship = $relationships[ $relationshipPropertyName ];

        # One-to-one and one-to-many relationships will be represented in this class as scalar database values rather than
        # arrays of related ids, so we can just return the value.
        if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_ONE_TO_ONE ||  $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_ONE_TO_MANY ) {
            return $this->getValue( $relationship->getValue( 'propertyName' ) );
        }

        $relatedClassName = $relationship->getValue( 'relatedClassName' );

        $relatedTableName = $relatedClassName::getSchema()->getValue( 'tableName' );

        # Get the select parameters to load the related class.
        $relatedSelectParams = $relatedClassName::getSchema()->getValue( 'selectParams' );

        $relatedTables = $relatedSelectParams->getValue( 'table' );
        $relatedOptions = $relatedSelectParams->getValue( 'options' );
        $relatedJoinConds = $relatedSelectParams->getValue( 'joinConds' );

        $relatedIds = [];

        $dbr = wfGetDB( DB_REPLICA );
        $dbResult = [];

        if( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
            $tablePrefix = is_array( $relatedTables ) ? array_search( $relatedTableName, $relatedTables ) . '.' : '';

            $vars = array_map( function( $fieldName ) use ( $tablePrefix ) {
                return $tablePrefix . $fieldName;
            }, $relatedClassName::getPrimaryKeyFields() );

            $conds = array_combine(
                array_map( function( $fieldName ) use ( $tablePrefix ) {
                        return $tablePrefix . $fieldName;
                    }, static::getPrimaryKeyFields()
                ), $this->getPrimaryKeyValues()
            );

            $dbResult = $dbr->select( $relatedTables, $vars, $conds, __METHOD__, $relatedOptions, $relatedJoinConds );
        } elseif( $relationship->getValue( 'relationshipType' ) === DatabaseClass::RELATIONSHIP_MANY_TO_MANY ) {
            $tables = [];

            if( !is_array( $relatedTables ) ) {
                # If the related tables variable contains a string with a single table name assign it a table alias
                # and convert it to an array
                $relatedTableAlias = 't1';

                $tables[ $relatedTableAlias ] = $relatedTables;
            } else {
                # If the related tables variable is already an array of table names, find the existing alias for the
                # related table
                $relatedTableAlias = array_search( $relatedTableName, $relatedTables );

                $tables = $relatedTables;
            }

            # Get the name of the junction table
            $junctionTableName = $relationship->getValue( 'junctionTableName' );

            # Create an alias for the junction table and add it to the tables array
            $junctionTableAlias = 't' . ( count( $tables ) + 1 );

            $tables[ $junctionTableAlias ] = $junctionTableName;

            # Select the primary keys for the related table
            $vars = array_map( function( $fieldName ) use ( $relatedTableAlias ) {
                return $relatedTableAlias . '.' . $fieldName;
            }, $relatedClassName::getPrimaryKeyFields() );

            # Only select rows whose primary key in the junction table matches this instance's primary key values
            $conds = array_combine(
                array_map( function( $fieldName ) use ( $junctionTableAlias ) {
                        return $junctionTableAlias . '.' . $fieldName;
                    }, static::getPrimaryKeyFields()
                ), $this->getPrimaryKeyValues()
            );

            # Initalize the options parameter to the related class's options
            $options = $relatedOptions;

            # Add related table alias to each field that doesn't already have one.
            # This expects a comma-delimited string of field names. Fields which already have a table prefix
            # are unchanged.
            if( isset( $options[ 'ORDER BY' ] ) ) {
                $orderByFields = explode( ',', $options[ 'ORDER BY' ] );

                foreach( $orderByFields as &$field ) {
                    $field = trim( $field );

                    if( strpos( $field, '.' ) === false ) {
                        $field = $relatedTableAlias . '.' . $field;
                    }
                }

                $options[ 'ORDER BY' ] = implode( ', ', $orderByFields );
            }

            $joinConds = $relatedJoinConds;

            # Add the junction table and conditions to the join_conds parameter
            $joinCondsConds = [];

            foreach( $relatedClassName::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                $joinCondsConds[] = $relatedTableAlias . '.' . $primaryKeyFieldName . ' = ' . $junctionTableAlias . '.' . $primaryKeyFieldName;
            }

            $joinConds[ $junctionTableAlias ] = array( 'INNER JOIN', $joinCondsConds );

            # Query the database
            $dbResult = $dbr->select( $tables, $vars, $conds, __METHOD__, $options, $joinConds );
        }

        foreach( $dbResult as $dbResultObject ) {
            # Add the id for each row to the return value
            if( count( $relatedClassName::getPrimaryKeyFields() ) == 1 ) {
                $primaryKeyFieldName = array_values( $relatedClassName::getPrimaryKeyFields() )[ 0 ];

                $prepareResult = $relatedClassName::prepareValue( $primaryKeyFieldName, $dbResultObject->{ $primaryKeyFieldName } );

                if( !$prepareResult->isOK() ) {
                    return false;
                }

                $newRelatedId = $prepareResult->getValue();
            } else {
                $newRelatedId = [];

                foreach( $relatedClassName::getPrimaryKeyFields() as $primaryKeyFieldName ) {
                    $prepareResult = $relatedClassName::prepareValue( $primaryKeyFieldName, $dbResultObject->{ $primaryKeyFieldName } );

                    if( !$prepareResult->isOK() ) {
                        return false;
                    }

                    $newRelatedId[ $primaryKeyFieldName ] = $prepareResult->getValue();
                }
            }

            $prepareResult = $relatedClassName::prepareId( $newRelatedId );

            if( !$prepareResult->isOK() ) {
                return false;
            }

            $relatedIds[] = $prepareResult->getValue();
        }

        return $relatedIds;
    }





    protected function isPrimaryKeySet() {
        return count( static::getPrimaryKeyFields() ) == count( array_filter( $this->getPrimaryKeyValues() ) );
    }





    protected function loadAutoloadRelatedIds(): bool {
        $relationships = static::getSchema()->getValue( 'relationships' );

        foreach( $relationships as $relationship ) {
            if( $relationship->getValue( 'autoload' ) ) {
                $setValueResult = $this->setValue(
                    $relationship->getValue( 'propertyName' ),
                    $this->getRelationshipValue( $relationship->getValue( get_class( $relationship )::getIdPropertyName() ) )
                );

                if( !$setValueResult->isOK() ) {
                    return false;
                }
            }
        }

        return true;
    }
}