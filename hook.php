<?php

function plugin_glpinetworkmap_install()
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $floorplans_table = 'glpi_plugin_glpinetworkmap_floorplans';
    if (!$DB->tableExists($floorplans_table)) {
        $query = "CREATE TABLE `$floorplans_table` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` tinyint NOT NULL DEFAULT 0,
            `image` varchar(255) DEFAULT NULL,
            `racks_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `racks_id` (`racks_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    } elseif (!$DB->fieldExists($floorplans_table, 'racks_id')) {
        // Migração para instalações já existentes do plugin (versão 1.0.0
        // não tinha vínculo com Rack - adicionado para permitir uma planta
        // por rack, exibida como aba na tela do rack).
        $DB->doQuery(
            "ALTER TABLE `$floorplans_table` ADD `racks_id` int {$default_key_sign} NOT NULL DEFAULT 0, "
            . "ADD KEY `racks_id` (`racks_id`)"
        ) or die($DB->error());
    }

    $markers_table = 'glpi_plugin_glpinetworkmap_markers';
    if (!$DB->tableExists($markers_table)) {
        $query = "CREATE TABLE `$markers_table` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_glpinetworkmap_floorplans_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `category` varchar(50) NOT NULL DEFAULT '',
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `pos_x` decimal(7,3) NOT NULL DEFAULT 0,
            `pos_y` decimal(7,3) NOT NULL DEFAULT 0,
            `label` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_glpinetworkmap_floorplans_id` (`plugin_glpinetworkmap_floorplans_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    } elseif (!$DB->fieldExists($markers_table, 'category')) {
        // Migração: v1.0 marcava só pelo itemtype (NetworkEquipment/Printer).
        // Agora a categoria visual (ícone/cor/legenda) é escolhida na hora de
        // criar o ponto e fica independente do itemtype - permite, por
        // exemplo, "Ponto de acesso" e "Switch" serem NetworkEquipment com
        // ícones/filtros diferentes, e "Ponto de rede" sem ativo nenhum.
        // Linhas existentes (todas de antes desta coluna existir, logo só
        // NetworkEquipment/Printer) migram para as categorias equivalentes.
        $DB->doQuery("ALTER TABLE `$markers_table` ADD `category` varchar(50) NOT NULL DEFAULT '', ADD KEY `category` (`category`)") or die($DB->error());
        $DB->update($markers_table, ['category' => 'access_point'], ['itemtype' => 'NetworkEquipment']);
        $DB->update($markers_table, ['category' => 'printer'], ['itemtype' => 'Printer']);
    }

    // Direito próprio do plugin. Concedido com ALLSTANDARDRIGHT aos perfis
    // nativos do GLPI de nível Técnico pra cima (Technician, Supervisor,
    // Admin, Super-Admin); Self-Service, Observer, Hotliner e Read-Only
    // ficam sem acesso - nem o menu aparece pra eles. IDs fixos dos perfis
    // padrão do GLPI (mesmos em toda instalação nova); se a SEBRAE criar
    // perfis customizados, o direito se ajusta em Administração > Perfis
    // como qualquer outro direito do GLPI.
    // Profile::update() não serve aqui: direitos de plugin não são colunas de
    // `glpi_profiles`, moram em `glpi_profilerights` e só a API de
    // ProfileRight escreve lá de verdade.
    ProfileRight::addProfileRights(['plugin_glpinetworkmap_floorplan']);
    foreach ([3, 4, 6, 7] as $profiles_id) { // Admin, Super-Admin, Technician, Supervisor
        ProfileRight::updateProfileRights($profiles_id, ['plugin_glpinetworkmap_floorplan' => ALLSTANDARDRIGHT]);
    }

    return true;
}

function plugin_glpinetworkmap_uninstall()
{
    global $DB;

    foreach ([
        'glpi_plugin_glpinetworkmap_markers',
        'glpi_plugin_glpinetworkmap_floorplans',
    ] as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `$table`");
    }

    ProfileRight::deleteProfileRights(['plugin_glpinetworkmap_floorplan']);

    return true;
}
