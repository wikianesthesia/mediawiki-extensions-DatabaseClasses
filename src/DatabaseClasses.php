<?php

namespace DatabaseClasses;

class DatabaseClasses {
    public static function init() {
        global $wgDatabaseClassesDisableCache, $wgDatabaseClassesCacheTTL;

        if( $wgDatabaseClassesDisableCache ) {
            //$wgDatabaseClassesCacheTTL = -1;
        }
    }
}