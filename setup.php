<?php

define('PLUGIN_GLPINETWORKMAP_VERSION', '1.0.0');
define('PLUGIN_GLPINETWORKMAP_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_GLPINETWORKMAP_MAX_GLPI_VERSION', '11.0.99');

function plugin_version_glpinetworkmap()
{
    return [
        'name'           => 'Mapa de Rede',
        'version'        => PLUGIN_GLPINETWORKMAP_VERSION,
        'author'         => 'SEBRAE',
        'license'        => 'GPL-3.0-or-later',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_GLPINETWORKMAP_MIN_GLPI_VERSION,
                'max' => PLUGIN_GLPINETWORKMAP_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_glpinetworkmap_check_prerequisites()
{
    if (!defined('GLPI_VERSION')) {
        return false;
    }

    return version_compare(GLPI_VERSION, PLUGIN_GLPINETWORKMAP_MIN_GLPI_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_GLPINETWORKMAP_MAX_GLPI_VERSION, '<=');
}

function plugin_glpinetworkmap_check_config()
{
    return true;
}

function plugin_init_glpinetworkmap()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpinetworkmap'] = true;

    // Leaflet e o JS/CSS do mapa só são carregados dentro de front/map.php
    // (via <link>/<script> direto na página), não como add_css/add_javascript
    // globais - senão o Leaflet inteiro seria baixado em toda página do GLPI
    // (lista de chamados, etc.) sem necessidade nenhuma.

    Plugin::registerClass('PluginGlpinetworkmapFloorplan', ['addtabon' => ['Rack']]);
    Plugin::registerClass('PluginGlpinetworkmapMarker');

    if (Session::getLoginUserID()) {
        // Setor 'assets' (mesmo do Rack nativo do GLPI) para aparecer junto
        // dos outros itens de infraestrutura em Ativos, não em Ferramentas.
        $PLUGIN_HOOKS['menu_toadd']['glpinetworkmap'] = [
            'assets' => 'PluginGlpinetworkmapFloorplan',
        ];
    }
}
