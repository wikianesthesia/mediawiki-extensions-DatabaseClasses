<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

namespace DatabaseClasses;

use MWException;

/**
 * Class DatabaseRelationship
 * @package DatabaseClasses
 */
class DatabaseRelationship extends DatabaseMetaClass {

    protected static $properties = [ [
            'name' => 'autoload',
            'defaultValue' => true,
            'type' => DatabaseClass::TYPE_BOOLEAN
        ], [
            'name' => 'junctionTableName',
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'name' => 'propertyName',
            'required' => true
        ], [
            'name' => 'relatedClassName',
            'required' => true,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => 'class_exists'
        ], [
            'name' => 'relatedPropertyName',
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'name' => 'relationshipType',
            'required' => true,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => DatabaseRelationship::class . '::isValidRelationshipType'
        ]
    ];

    protected static $idPropertyName = 'propertyName';

    protected static $validRelationshipTypes = [
        DatabaseClass::RELATIONSHIP_MANY_TO_MANY,
        DatabaseClass::RELATIONSHIP_MANY_TO_ONE,
        DatabaseClass::RELATIONSHIP_ONE_TO_MANY,
        DatabaseClass::RELATIONSHIP_ONE_TO_ONE,
    ];

    public static function isValidRelationshipType( string $relationshipType ) {
        return in_array( $relationshipType, static::$validRelationshipTypes );
    }

    public static function newFromValues( array $values ) {
        $object = parent::newFromValues( $values );

        if( !$object ) {
            return false;
        }

        # Could be a string or an array
        $propertyNames = $object->getValue( 'propertyName' );
        $relationshipType = $object->getValue( 'relationshipType' );

        # Could be a string or an array
        $relatedPropertyNames = $object->getValue( 'relatedPropertyName' );

        if( $relationshipType === DatabaseClass::RELATIONSHIP_MANY_TO_MANY ) {
            if( !is_string( $propertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-many-to-many-propertyname-must-be-string',
                    var_export( $propertyNames )
                )->text() );
            }

            if( $relatedPropertyNames && !is_string( $relatedPropertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-many-to-many-relatedpropertyname-must-be-string',
                    var_export( $relatedPropertyNames )
                )->text() );
            }

            # A junction table must be defined
            if( !$object->getValue( 'junctionTableName' ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-junction-table-not-defined',
                    $object->getValue( static::getIdPropertyName() )
                )->text() );
            }
        } elseif( $relationshipType === DatabaseClass::RELATIONSHIP_MANY_TO_ONE ) {
            if( !is_string( $propertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-many-to-one-propertyname-must-be-string',
                    var_export( $propertyNames )
                )->text() );
            }

            if( $relatedPropertyNames && !is_string( $relatedPropertyNames ) && !is_array( $relatedPropertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-many-to-one-relatedpropertyname-must-be-string-or-array',
                    var_export( $relatedPropertyNames )
                )->text() );
            }
        } elseif( $relationshipType === DatabaseClass::RELATIONSHIP_ONE_TO_MANY ) {
            if( !is_string( $propertyNames ) && !is_array( $propertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-one-to-many-propertyname-must-be-string-or-array',
                    var_export( $propertyNames )
                )->text() );
            }

            if( $relatedPropertyNames && !is_string( $relatedPropertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-one-to-many-relatedpropertyname-must-be-string',
                    var_export( $relatedPropertyNames )
                )->text() );
            }
        } elseif( $relationshipType === DatabaseClass::RELATIONSHIP_ONE_TO_ONE ) {
            if( !is_string( $propertyNames ) && !is_array( $propertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-one-to-one-propertyname-must-be-string-or-array',
                    var_export( $propertyNames )
                )->text() );
            }

            if( $relatedPropertyNames && !is_string( $relatedPropertyNames ) && !is_array( $relatedPropertyNames ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relationship-one-to-one-relatedpropertyname-must-be-string-or-array',
                    var_export( $relatedPropertyNames )
                )->text() );
            }
        }

        $relatedPropertyNames = $relatedPropertyNames ? ( is_array( $relatedPropertyNames ) ? $relatedPropertyNames : [ $relatedPropertyNames ] ) : [];

        # If related property name(s) are defined, perform additional validation
        if( !empty( $relatedPropertyNames ) ) {
            $relatedClassName = $object->getValue( 'relatedClassName' );

            # Related property name is only sensible when the related class name is defined
            # and set to a subclass of DatabaseClass.
            if( !$relatedClassName || !DatabaseClass::hasSubclass( $relatedClassName ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-relatedpropertyname-only-valid-for-subclass',
                    $object->getValue( static::getIdPropertyName() ),
                    DatabaseClass::class
                )->text() );
            }

            $relatedPropertyNames = is_array( $relatedPropertyNames ) ? $relatedPropertyNames : [ $relatedPropertyNames ];

            foreach( $relatedPropertyNames as $relatedPropertyName ) {
                $relatedProperty = $relatedClassName::getProperty( $relatedPropertyName );

                # The property must exist in the related class
                if( !$relatedProperty ) {
                    throw new MWException( wfMessage(
                        'databaseclasses-exception-relatedproperty-does-not-exist-for-relatedclass',
                        $object->getValue( static::getIdPropertyName() ),
                        $relatedPropertyName,
                        $relatedClassName
                    )->text() );
                }
            }
        }

        return $object;
    }
}