<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

namespace DatabaseClasses;

use MWException;

/**
 * Class DatabaseSchema
 * @package DatabaseClasses
 */
class DatabaseSchema extends DatabaseMetaClass {

    protected static $properties = [ [
            'name' => 'className',
            'required' => true,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => DatabaseClass::class . '::validateSubclass'
        ], [
            'name' => 'fields',
            'required' => true,
            'type' => DatabaseClass::TYPE_OBJECTARRAY,
            'className' => DatabaseField::class
        ], [
            'name' => 'orderBy',
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'name' => 'primaryKey',
            'required' => true,
        ], [
            'name' => 'relationships',
            'defaultValue' => [],
            'required' => false,
            'type' => DatabaseClass::TYPE_OBJECTARRAY,
            'className' => DatabaseRelationship::class
        ], [
            'name' => 'selectParams',
            'required' => false,
            'type' => DatabaseClass::TYPE_OBJECT,
            'className' => DatabaseSelectParams::class
        ], [
            'name' => 'tableName',
            'required' => true,
            'type' => DatabaseClass::TYPE_STRING
        ], [
            'defaultValue' => [],
            'name' => 'uniqueFields',
            'type' => DatabaseClass::TYPE_ARRAY
        ]
    ];

    protected static $idPropertyName = 'className';

    public function getAllFields() {
        return $this->getValue( 'fields' );
    }

    public function getField( $fieldId ) {
        return isset( $this->getValue( 'fields' )[ $fieldId ] ) ? $this->getValue( 'fields' )[ $fieldId ] : false;
    }

    public function getRelationship( $relationshipId ) {
        $relationshipId = is_array( $relationshipId ) ? serialize( $relationshipId ) : $relationshipId;

        return isset( $this->getValue( 'relationships' )[ $relationshipId ] ) ? $this->getValue( 'relationships' )[ $relationshipId ] : false;
    }


    public static function newFromValues( array $values ) {
        $object = parent::newFromValues( $values );

        if( !$object ) {
            return false;
        }

        $className = $object->getValue( 'className' );

        # Make sure all the fields referenced by 'primaryKey' are actually defined in 'fields'
        $primaryKey = is_array( $object->getValue( 'primaryKey' ) ) ? $object->getValue( 'primaryKey' ) : [ $object->getValue( 'primaryKey' ) ];

        # All primary key fields must reference a property name which exists as a database field for the class
        foreach( $primaryKey as $primaryKeyFieldName ) {
            if( !isset( $object->getValue( 'fields' )[ $primaryKeyFieldName ] ) ) {
                throw new MWException( wfMessage(
                    'databaseclasses-exception-id-field-not-defined-in-fields',
                    $primaryKeyFieldName,
                    $object->getValue( 'className' )
                )->text() );
            }
        }

        $uniqueFields = $object->getValue( 'uniqueFields' );

        # All unique fields must reference a property name which exists as a database field for the class
        if( $uniqueFields ) {
            foreach( $uniqueFields as $uniqueFieldGroup ) {
                foreach( $uniqueFieldGroup as $uniqueFieldName ) {
                    if( !isset( $object->getValue( 'fields' )[ $uniqueFieldName ] ) ) {
                        throw new MWException( wfMessage(
                            'databaseclasses-exception-unique-field-not-defined-in-fields',
                            $uniqueFieldName,
                            $object->getValue( 'className' )
                        )->text() );
                    }
                }
            }
        }

        if( !in_array( $primaryKey, $uniqueFields ) ) {
            $uniqueFields = array_merge( [ $primaryKey ], $uniqueFields );

            $object->setValue( 'uniqueFields', $uniqueFields );
        }

        # If select params aren't explicitly configured, create an object for them and copy the orderBy value to it
        if( !$object->getValue( 'selectParams' ) ) {
            $selectParamValues[ 'table '] = $object->getValue( 'tableName' );

            if( $object->getValue( 'orderBy' ) ) {
                $selectParamValues[ 'options' ] = [
                    'ORDER BY' => $object->getValue( 'orderBy' )
                ];
            }

            $selectParams = DatabaseSelectParams::newFromValues( $selectParamValues );

            if( !$selectParams ) {
                return false;
            }

            $object->setValue( 'selectParams', $selectParams );
        }

        return $object;
    }
}
