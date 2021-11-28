<?php
/**
 * @author Chris Rishel <chris.rishel@wikianesthesia.org>
 */

namespace DatabaseClasses;

/**
 * Class DatabaseSelectParams
 * @package DatabaseClasses
 */
class DatabaseSelectParams extends DatabaseMetaClass {

    protected static $properties = [ [
            'name' => 'joinConds',
            'defaultValue' => [],
            'required' => false,
            'type' => DatabaseClass::TYPE_ARRAY
        ], [
            'name' => 'options',
            'defaultValue' => [],
            'required' => false,
            'type' => DatabaseClass::TYPE_ARRAY
        ], [
            'name' => 'table',
            'required' => false,
            'validator' => DatabaseSelectParams::class . '::isValidTable'
        ]
    ];

    protected static $idPropertyName = '';

    public static function isValidTable( $table ) {
        return is_string( $table ) || is_array( $table );
    }
}