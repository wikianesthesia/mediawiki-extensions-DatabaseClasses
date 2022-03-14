<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */
namespace DatabaseClasses;

use MWException;
use Status;
use User;

/**
 * Class DatabaseProperty
 * @package DatabaseClasses
 */
class DatabaseProperty extends DatabaseMetaClass {

    protected static $properties = [ [
            'name' => 'className',
            'defaultValue' => '',
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => DatabaseMetaClass::class . '::validateSubclass'
        ], [
            'name' => 'defaultValue',
            'required' => false,
        ], [
            'name' => 'name',
            'required' => true,
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'name' => 'required',
            'defaultValue' => false,
            'required' => false,
            'type' => DatabaseClass::TYPE_BOOLEAN
        ], [
            'name' => 'source',
            'defaultValue' => DatabaseProperty::class,
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'name' => 'type',
            'defaultValue' => '',
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => __CLASS__ . '::isValidType'
        ], [
            'name' => 'validator',
            'defaultValue' => '',
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING
        ]
    ];

    protected static $idPropertyName = 'name';

    protected static $validTypes = [
        DatabaseClass::TYPE_ARRAY,
        DatabaseClass::TYPE_BOOLEAN,
        DatabaseClass::TYPE_COLOR,
        DatabaseClass::TYPE_DOUBLE,
        DatabaseClass::TYPE_INTEGER,
        DatabaseClass::TYPE_JSON,
        DatabaseClass::TYPE_OBJECT,
        DatabaseClass::TYPE_OBJECTARRAY,
        DatabaseClass::TYPE_STRING,
        DatabaseClass::TYPE_UNSIGNED_INTEGER
    ];

    public static function newFromValues( array $values ) {
        $object = parent::newFromValues( $values );

        if( !$object ) {
            return false;
        }

        if( isset( $values[ 'createObjects' ] ) ) {
            if( ( !$object->getValue( 'className' ) || !$object->getValue( 'type' ) === 'array' ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-createobjects-invalid'
                )->text() );
            }
        }

        return $object;
    }





    public static function isValidType( string $type ) {
        return in_array( $type, static::$validTypes );
    }





    public static function registerProperties() {
        # DatabaseProperty::registerProperties() can't use any of the helper functions which rely on getProperty(),
        # so we have to directly parse and set the properties here.
        global $wgDatabaseClassesDbInfo;

        if( !isset( $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ] ) ) {
            $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ] = [];

            foreach( static::$properties as $propertyData ) {
                $property = New static();

                $propertyName = $propertyData[ static::getIdPropertyName() ];

                foreach( static::$properties as $propertyPropertyData ) {
                    $propertyPropertyName = $propertyPropertyData[ static::getIdPropertyName() ];

                    $property->values[ $propertyPropertyName ] = isset( $propertyData[ $propertyPropertyName ] ) ? $propertyData[ $propertyPropertyName ] :
                        ( isset( $propertyPropertyData[ 'defaultValue' ] ) ? $propertyPropertyData[ 'defaultValue' ] : null );
                }

                $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ][ $propertyName ] = $property;
            }
        }

        return true;
    }




    protected static function validateUser( int $user_id ): Status {
        # TODO this probably isn't callable... Need to move into something like DatabaseValidators/Factory...
        $result = Status::newGood();

        if( !User::newFromId( $user_id )->loadFromDatabase() ) {
            $error = wfMessage(
                'databaseclasses-exception-object-does-not-exist',
                User::class,
                'user_id',
                $user_id
            )->text();

            static::handleError( $error );

            $result->fatal( $error );

            return $result;
        }

        return $result;
    }

    public function getType() {
        return $this->getValue( 'type' );
    }

    public function isRequired(): bool {
        return $this->getValue( 'required' );
    }
}
