<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

// TODO convert validators to return Status

namespace DatabaseClasses;

use MWException;
use MediaWiki\Logger\LoggerFactory;
use Status;

/**
 * Class DatabaseMetaClass
 * @package DatabaseClasses
 */
abstract class DatabaseMetaClass {

    protected static $properties;
    protected static $idPropertyName = '';

    private const EXTENSION_NAME = 'DatabaseClasses';

    protected $values = [];




    public static function getDefaultValue( string $propertyName ) {
        static::validatePropertyName( $propertyName );

        $property = static::getProperty( $propertyName );

        $defaultValue = $property->isValueSet( 'defaultValue' ) ? $property->getValue( 'defaultValue' ) : null;

        $prepareResult = static::prepareValue( $propertyName, $defaultValue );

        return $prepareResult->isOK() ? $prepareResult->getValue() : null;
    }


    public static function getIdPropertyName(): string {
        return static::$idPropertyName;
    }



    public static function getProperties() {
        global $wgDatabaseClassesDbInfo;

        if( !static::registerProperties() ) {
            return false;
        }

        return $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ];
    }



    public static function getPropertiesJson() {
        $properties = [];

        foreach( static::$properties as $propertyData ) {
            $propertyName = $propertyData[ DatabaseProperty::getIdPropertyName() ];
            unset( $propertyData[ DatabaseProperty::getIdPropertyName() ] );

            $properties[ $propertyName ] = $propertyData;
        }

        return json_encode( $properties );
    }




    public static function getProperty( string $propertyName ) {
        global $wgDatabaseClassesDbInfo;

        return static::hasProperty( $propertyName )
            ? $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ][ $propertyName ]
            : false;
    }


    public static function hasProperty( string $propertyName ): bool {
        global $wgDatabaseClassesDbInfo;

        if( !static::registerProperties() ) {
            return false;
        } elseif( !isset( $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ][ $propertyName ] ) ) {
            return false;
        }

        return true;
    }




    public static function hasSubclass( string $subclassName ) {
        return class_exists( $subclassName ) && is_subclass_of( $subclassName, static::class );
    }




    public static function newFromValues( array $values ) {
        $object = New static();

        $properties = static::getProperties();

        if( !$properties ) {
            return false;
        }

        # Iterate through the class properties to validate and assign values to the instance
        foreach( $properties as $property ) {
            $propertyName = $property->getValue( DatabaseProperty::getIdPropertyName() );

            if( !isset( $values[ $propertyName ] ) ) {
                if( $property->getValue( 'defaultValue' ) !== null ) {
                    $values[ $propertyName ] = $property->getValue( 'defaultValue' );
                } elseif( $property->getValue( 'required' ) ) {
                    # Required property not defined
                    static::handleError( wfMessage(
                        'databaseclasses-exception-property-required',
                        $propertyName,
                        static::class
                    )->text() );

                    return false;
                } else {
                    continue;
                }
            }

            $setValueResult = $object->setValue( $propertyName, $values[ $propertyName ] );

            if( !$setValueResult->isOK() ) {
                return false;
            }
        }

        return $object;
    }


    /**
     * @param string $message
     */
    protected static function handleError( string $message ) {
        global $wgDatabaseClassesThrowExceptionOnError;

        $e = new MWException( $message );

        if( $wgDatabaseClassesThrowExceptionOnError ) {
            static::log( 'error', $message );

            Throw $e;
        } else {
            static::log( 'error', $message, [ 'exception' => $e ] );
        }
    }


    protected static function log( string $level, string $message, array $context = [] ) {
        $logger = LoggerFactory::getInstance( self::EXTENSION_NAME );

        $logger->log( $level, $message, $context );
    }



    protected static function prepareValue( string $propertyName, $value ): Status {
        $result = Status::newGood();

        static::validatePropertyName( $propertyName );

        $property = static::getProperty( $propertyName );

        $propertyClassName = $property->getValue( 'className' );
        $propertyType = $property->getValue( 'type' );

        # If it is a string (regardless of declared type), remove whitespace (and related character) padding
        $value = is_string( $value ) ? trim( $value ) : $value;

        if( $propertyType ) {
            $typeMismatch = false;

            if( $propertyType === DatabaseClass::TYPE_ARRAY ) {
                if( !is_array( $value ) ) {
                    $typeMismatch = true;
                }
            } elseif( $propertyType === DatabaseClass::TYPE_COLOR ) {
                // Currently only supports 3 or 6 digit hex colors with preceding '#'
                $regexpColor = '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/';

                if( $value && !preg_match( $regexpColor, $value ) ) {
                    $typeMismatch = true;
                }
            } elseif( $propertyType === DatabaseClass::TYPE_OBJECT ) {
                if( !is_object( $value ) ) {
                    if( is_array( $value ) && $propertyClassName ) {
                        $value = $propertyClassName::newFromValues( $value );
                    } else {
                        $typeMismatch = true;
                    }
                }
            } elseif( $propertyType === DatabaseClass::TYPE_OBJECTARRAY ) {
                if( !is_array( $value ) ) {
                    $typeMismatch = true;
                } else {
                    foreach( $value as $i => $valueData ) {
                        if( !is_object( $valueData ) ) {
                            if( is_array( $value ) && $propertyClassName ) {
                                $id = is_array( $valueData[ $propertyClassName::getIdPropertyName() ] ) ? serialize( $valueData[ $propertyClassName::getIdPropertyName() ] ) : $valueData[ $propertyClassName::getIdPropertyName() ];

                                $value[ $id ] = $propertyClassName::newFromValues( $valueData );

                                unset( $value[$i] );
                            } else {
                                $typeMismatch = true;

                                break;
                            }
                        }
                    }
                }
            } elseif( $propertyType === DatabaseClass::TYPE_JSON ) {
                # TODO
            } else {
                # Special case for unsigned integers, we have to change the variable passed to settype()
                # to integer and then specifically check that it's not negative after.
                if( $propertyType === DatabaseClass::TYPE_UNSIGNED_INTEGER ) {
                    $propertyType = DatabaseClass::TYPE_INTEGER;
                }

                # Try to cast value to the required type and keep the casted version
                if( !settype( $value, $propertyType ) ) {
                    $typeMismatch = true;
                } elseif( $property->getValue( 'type' ) === DatabaseClass::TYPE_UNSIGNED_INTEGER && $value < 0 ) {
                    $typeMismatch = true;

                    # Set property type back to the correct value for error handling
                    $propertyType = DatabaseClass::TYPE_UNSIGNED_INTEGER;
                }
            }

            if( $typeMismatch ) {
                $error = wfMessage(
                    'databaseclasses-exception-type-mismatch',
                    $propertyName,
                    static::class,
                    $propertyType,
                    gettype( $value )
                )->text();

                static::handleError( $error );

                $result->fatal( $error, [
                    'error' => DatabaseClass::ERROR_TYPEMISMATCH,
                    'propertyName' => $propertyName
                ] );

                return $result;
            }
        }

        $result->setResult( true, $value );

        return $result;
    }



    protected static function registerProperties() {
        global $wgDatabaseClassesDbInfo;

        if( !isset( $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ] ) ) {
            if( empty( static::$properties ) ) {
                static::handleError( wfMessage(
                    'databaseclasses-exception-properties-not-defined-for-class',
                    static::class
                )->text() );

                return false;
            }

            $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ] = [];

            foreach( static::$properties as $propertyData ) {
                $propertyName = $propertyData[ DatabaseProperty::getIdPropertyName() ];
                $property = DatabaseProperty::newFromValues( $propertyData );

                if( !$property ) {
                    return false;
                }

                $wgDatabaseClassesDbInfo[ static::class ][ 'properties' ][ $propertyName ] = $property;
            }
        }

        return true;
    }




    protected static function validatePropertyName( string $propertyName ): bool {
        if( !static::getProperty( $propertyName ) ) {
            throw new MWException( wfMessage(
                'databaseclasses-exception-invalid-property-for-class',
                $propertyName,
                static::class
            )->text() );
        }

        return true;
    }





    protected static function validateSubclass( string $subclassName ): bool {
        if( !class_exists( $subclassName ) ) {
            throw new MWException( wfMessage(
                'databaseclasses-exception-class-does-not-exist',
                $subclassName
            )->text() );
        }

        if( !is_subclass_of( $subclassName, static::class ) ) {
            throw new MWException( wfMessage(
                'databaseclasses-exception-class-is-not-subclass-of',
                $subclassName,
                static::class
            )->text() );
        }

        return true;
    }



    public function areValuesSet( $propertyNames ) {
        # Allow this function to be called with just one property as a string
        if( !is_array( $propertyNames ) ) {
            $propertyNames = [ $propertyNames ];
        }

        foreach( $propertyNames as $propertyName ) {
            if( !$this->isValueSet( $propertyName ) ) {
                return false;
            }
        }

        return true;
    }




    public function getValue( $propertyName ) {
        # Allow this function to be called with an array of property names
        if( is_array( $propertyName ) ) {
            return $this->getValues( $propertyName );
        }

        # isValueSet and getDefaultValue will validate the property name
        return $this->isValueSet( $propertyName ) ? $this->values[ $propertyName ] : static::getDefaultValue( $propertyName );
    }




    public function getValues( $propertyNames ): array {
        # Allow this function to be called with just one property as a string and still return an array
        if( !is_array( $propertyNames ) ) {
            $propertyNames = [ $propertyNames ];
        }

        $values = [];

        foreach( $propertyNames as $propertyName ) {
            $values[ $propertyName ] = $this->getValue( $propertyName );
        }

        return $values;
    }



    public function isValueSet( $propertyName ) {
        static::validatePropertyName( $propertyName );

        return isset( $this->values[ $propertyName ] );
    }




    public function setValue( string $propertyName, $value ): Status {
        $result = Status::newGood();

        $property = static::getProperty( $propertyName );

        if( !$property ) {
            $error = wfMessage(
                'databaseclasses-exception-invalid-property-for-class',
                $propertyName,
                static::class
            )->text();

            static::handleError( $error );

            $result->fatal( $error, [
                'error' => DatabaseClass::ERROR_INVALIDPROPERTY,
                'propertyName' => $propertyName
            ] );

            return $result;
        }

        if( $property->getValue( 'required' ) && !$value ) {
            $error = wfMessage(
                'databaseclasses-exception-property-required',
                $propertyName,
                static::class
            )->text();

            static::handleError( $error );

            $result->fatal( $error, [
                'error' => DatabaseClass::ERROR_REQUIREDMISSING,
                'propertyName' => $propertyName
            ] );

            return $result;
        }

        $prepareResult = static::prepareValue( $propertyName, $value );

        if( !$prepareResult->isOK() ) {
            return $prepareResult;
        }

        $value = $prepareResult->getValue();

        if( $value ) {
            $propertyValidator = $property->getValue( 'validator' );

            if( $propertyValidator ) {
                $validatorResult = false;

                if( method_exists( $this, $propertyValidator ) ) {
                    $validatorResult = $this->$propertyValidator( $value );
                } elseif( is_callable( $propertyValidator ) ) {
                    $validatorResult = $propertyValidator( $value );
                } else {
                    $error = wfMessage(
                        'databaseclasses-exception-validator-not-callable',
                        $propertyValidator
                    )->text();

                    static::handleError( $error );

                    $result->fatal( $error, [
                        'error' => DatabaseClass::ERROR_INTERNALERROR,
                        'propertyName' => $propertyName
                    ] );

                    return $result;
                }

                $errorMessage = '';

                if( $validatorResult instanceof Status ) {
                    if( !$validatorResult->isOK() && count( $validatorResult->getErrors() ) === 1 ) {
                        $errorMessage = $validatorResult->getErrors()[ 0 ][ 'message' ];
                    }

                    $validatorResult = $validatorResult->isOK();
                }

                if( !$validatorResult ) {
                    $error = wfMessage(
                        'databaseclasses-exception-property-failed-validation',
                        $propertyName,
                        static::class,
                        $value
                    )->text();

                    static::handleError( $error );

                    $errorParams = [
                        'error' => DatabaseClass::ERROR_FAILEDVALIDATION,
                        'propertyName' => $propertyName
                    ];

                    if( $errorMessage ) {
                        $errorParams[ 'message' ] = $errorMessage;
                    }

                    $result->fatal( $error, $errorParams );

                    return $result;
                }
            }
        }

        $this->values[ $propertyName ] = $value;

        return $result;
    }




    public function setValues( array $values ): Status {
        $result = Status::newGood();

        foreach( $values as $propertyName => $value ) {
            $setValueResult = $this->setValue( $propertyName, $value );

            if( !$setValueResult->isOK() ) {
                return $setValueResult;
            }
        }

        return $result;
    }
}