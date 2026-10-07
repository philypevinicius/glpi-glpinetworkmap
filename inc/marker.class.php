<?php

class PluginGlpinetworkmapMarker extends CommonDBTM
{
    public static $rightname = 'plugin_glpinetworkmap_floorplan';

    public static function getTypeName($nb = 0)
    {
        return _n('Ponto no mapa', 'Pontos no mapa', $nb);
    }

    /**
     * Categorias visuais dos marcadores. Cada uma define ícone/cor/legenda e
     * o itemtype do GLPI ao qual se vincula - ou `itemtype => null` para
     * "Ponto de rede", que não representa um ativo cadastrado, só um nome
     * livre (ex: etiqueta de uma tomada de rede na parede).
     *
     * "Ponto de acesso", "Switch" e "Câmera" apontam todos para
     * NetworkEquipment: o GLPI não distingue esses papéis em itemtypes
     * diferentes, então a categoria (escolhida na hora de criar o ponto) é
     * quem decide o ícone/cor/filtro, independente do tipo cadastrado no
     * ativo em si.
     *
     * Adicionar uma categoria nova = uma entrada aqui. getItemSummary() já
     * lida com qualquer itemtype de forma genérica (nome/local/status/série);
     * só precisa de tratamento especial em getItemSummary() se o itemtype
     * novo tiver um dado que não seja um campo direto da tabela (como o IP
     * do NetworkEquipment, que mora em outra tabela).
     */
    public static function getCategories(): array
    {
        return [
            'access_point' => [
                'label'    => __('Ponto de acesso'),
                'icon'     => 'ti-wifi',
                'color'    => '#16a34a',
                'itemtype' => 'NetworkEquipment',
            ],
            'switch' => [
                'label'    => __('Switch'),
                'icon'     => 'ti-topology-star-3',
                'color'    => '#f97316',
                'itemtype' => 'NetworkEquipment',
            ],
            'camera' => [
                'label'    => __('Câmera'),
                'icon'     => 'ti-camera',
                'color'    => '#dc2626',
                'itemtype' => 'NetworkEquipment',
            ],
            'printer' => [
                'label'    => __('Impressora'),
                'icon'     => 'ti-printer',
                'color'    => '#7c3aed',
                'itemtype' => 'Printer',
            ],
            'rack' => [
                'label'    => __('Rack'),
                'icon'     => 'ti-server-2',
                'color'    => '#2563eb',
                'itemtype' => 'Rack',
            ],
            'network_point' => [
                'label'    => __('Ponto de rede'),
                'icon'     => 'ti-point-filled',
                'color'    => '#111827',
                'itemtype' => null,
            ],
        ];
    }

    public static function isCategoryValid(string $category): bool
    {
        return array_key_exists($category, self::getCategories());
    }

    /**
     * Itemtype do GLPI por trás de uma categoria, ou null se a categoria é
     * um ponto manual sem ativo vinculado (ex: "Ponto de rede").
     */
    public static function getItemtypeForCategory(string $category): ?string
    {
        return self::getCategories()[$category]['itemtype'] ?? null;
    }

    /**
     * Resumo do ativo vinculado a um marcador, usado tanto na busca (ao
     * criar o ponto) quanto no hover do mapa. Campos comuns (nome, local,
     * status, série, nº de inventário) são lidos de forma genérica; só
     * NetworkEquipment tem um `case` próprio, pelo IP não morar direto na
     * tabela do itemtype (ver comentário abaixo).
     */
    public static function getItemSummary(string $itemtype, int $items_id): ?array
    {
        if (!class_exists($itemtype)) {
            return null;
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return null;
        }

        global $CFG_GLPI;

        $summary = [
            'itemtype'         => $itemtype,
            'items_id'         => $items_id,
            'type_name'        => $itemtype::getTypeName(1),
            'name'             => $item->fields['name'] ?? '',
            'serial'           => $item->fields['serial'] ?? '',
            'inventory_number' => $item->fields['otherserial'] ?? '',
            'comment'          => $item->fields['comment'] ?? '',
            'location'         => '',
            'status'           => '',
            'link'             => $CFG_GLPI['root_doc'] . '/front/' . strtolower($itemtype) . '.form.php?id=' . $items_id,
        ];

        if (!empty($item->fields['locations_id'])) {
            $summary['location'] = Dropdown::getDropdownName('glpi_locations', $item->fields['locations_id']);
        }

        if (!empty($item->fields['states_id'])) {
            $summary['status'] = Dropdown::getDropdownName('glpi_states', $item->fields['states_id']);
        }

        switch ($itemtype) {
            case 'NetworkEquipment':
                // Endereço IP: não fica no NetworkEquipment em si, mora nas
                // NetworkName ligadas às NetworkPort do equipamento - por
                // isso a busca via query direta em vez de $item->fields.
                global $DB;
                $ip = $DB->request([
                    'SELECT' => ['glpi_ipaddresses.name AS ip'],
                    'FROM'   => 'glpi_networkports',
                    'INNER JOIN' => [
                        'glpi_networknames' => [
                            'ON' => [
                                'glpi_networknames' => 'items_id',
                                'glpi_networkports' => 'id',
                                ['AND' => ['glpi_networknames.itemtype' => 'NetworkPort']],
                            ],
                        ],
                        'glpi_ipaddresses' => [
                            'ON' => [
                                'glpi_ipaddresses' => 'items_id',
                                'glpi_networknames' => 'id',
                                ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']],
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_networkports.itemtype' => 'NetworkEquipment',
                        'glpi_networkports.items_id' => $items_id,
                    ],
                    'LIMIT' => 1,
                ]);
                foreach ($ip as $row) {
                    $summary['ip'] = $row['ip'];
                }
                break;
        }

        return $summary;
    }
}
