<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

namespace DatabaseClasses;

/**
 * Class DatabaseField
 * @package DatabaseClasses
 */
class DatabaseField extends DatabaseMetaClass {

    protected static $properties = [ [
            'name' => 'autoincrement',
            'defaultValue' => false,
            'required' => false,
            'type' => DatabaseClass::TYPE_BOOLEAN
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
            'name' => 'size',
            'defaultValue' => null,
            'required' => false
        ], [
            'name' => 'type',
            'required' => false,
            'type' => DatabaseClass::TYPE_STRING,
            'validator' => DatabaseProperty::class . '::isValidType'
        ]
    ];

    protected static $idPropertyName = 'name';

    public function getName(): string {
        return $this->getValue( 'name' );
    }

    public function getSize() {
        return $this->getValue( 'size' );
    }

    public function getType() {
        return $this->getValue( 'type' );
    }

    public function isAutoincrement() {
        return $this->getValue( 'autoincrement' );
    }

    public function isRequired() {
        return $this->getValue( 'required' );
    }
}