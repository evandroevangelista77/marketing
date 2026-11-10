<?php

namespace GlpiPlugin\Marketing;

use CommonDBTM;

/**
 * Base das classes do plugin com o direito "plugin_marketing".
 *
 * O GLPI 12 exige propriedades tipadas (public static string $rightname, public bool $dohistory)
 * e o GLPI 11 as declara sem tipo; o PHP não aceita uma subclasse que mude o tipo da propriedade.
 * Por isso a base é declarada de um jeito em cada versão e as demais classes só herdam dela.
 */
if (version_compare(GLPI_VERSION, '12.0.0-dev', '>=')) {
    abstract class Base extends CommonDBTM
    {
        public static string $rightname = 'plugin_marketing';
        public bool $dohistory = true;
    }
} else {
    abstract class Base extends CommonDBTM
    {
        public static $rightname = 'plugin_marketing';
        public $dohistory = true;
    }
}
