<?php

class PluginGlpinetworkmapFloorplan extends CommonDBTM
{
    public static $rightname = 'plugin_glpinetworkmap_floorplan';

    public static function getTypeName($nb = 0)
    {
        return _n('Planta de rede', 'Plantas de rede', $nb);
    }

    public static function getIcon()
    {
        return 'ti ti-map-2';
    }

    public static function getMenuName()
    {
        return self::getTypeName(2);
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title']           = static::getMenuName();
            $menu['page']            = '/plugins/glpinetworkmap/front/floorplan.php';
            $menu['icon']            = static::getIcon();
            $menu['links']['search'] = '/plugins/glpinetworkmap/front/floorplan.php';
            if (static::canCreate()) {
                $menu['links']['add'] = '/plugins/glpinetworkmap/front/floorplan.form.php';
            }
        }
        return $menu;
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['name'])) {
            Session::addMessageAfterRedirect(__('O nome da planta é obrigatório.'), false, ERROR);
            return false;
        }

        $input['racks_id'] = (int) ($input['racks_id'] ?? 0);

        if ($input['racks_id'] > 0) {
            // Planta vinculada a um rack herda a entidade do rack, em vez de
            // usar a entidade ativa da sessão - o rack pode estar numa
            // entidade diferente de onde o usuário está navegando no
            // momento em que cria a planta pela aba.
            $rack = new Rack();
            if ($rack->getFromDB($input['racks_id'])) {
                $input['entities_id'] = $rack->fields['entities_id'];
            }
        }

        $input['entities_id']  = $input['entities_id'] ?? Session::getActiveEntity();
        $input['is_recursive'] = $input['is_recursive'] ?? 0;
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['name']) && empty($input['name'])) {
            Session::addMessageAfterRedirect(__('O nome da planta é obrigatório.'), false, ERROR);
            return false;
        }
        return $input;
    }

    public function post_purgeItem()
    {
        // Remove os marcadores associados e o arquivo de imagem do disco -
        // sem isto a planta some da listagem mas deixa lixo órfão (linhas na
        // tabela de marcadores e o arquivo em GLPI_PLUGIN_DOC_DIR).
        global $DB;

        $DB->delete('glpi_plugin_glpinetworkmap_markers', [
            'plugin_glpinetworkmap_floorplans_id' => $this->getID(),
        ]);

        if (!empty($this->fields['image'])) {
            $path = self::getImageDir() . '/' . $this->fields['image'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function getImageDir(): string
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/glpinetworkmap/floorplans';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function getImageUrl(): string
    {
        global $CFG_GLPI;

        if (empty($this->fields['image'])) {
            return '';
        }

        return $CFG_GLPI['root_doc'] . '/front/pluginimage.send.php'
            . '?plugin=glpinetworkmap&folder=floorplans&name=' . rawurlencode($this->fields['image']);
    }

    public static function getFromRack(int $racks_id): ?self
    {
        if ($racks_id <= 0) {
            return null;
        }

        global $DB;

        // Query direta em vez de getFromDBByCrit(): essa lança
        // TooManyResultsException se mais de uma linha bater com o
        // critério, e prepareInputForAdd() por si só não impede duas
        // plantas apontando pro mesmo rack (só floorplan.form.php faz essa
        // checagem, na criação). Pegando a mais recente aqui, a tela do
        // rack sempre abre com alguma planta em vez de quebrar.
        $rows = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['racks_id' => $racks_id],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ]);

        foreach ($rows as $row) {
            $floorplan = new self();
            if ($floorplan->getFromDB($row['id'])) {
                return $floorplan;
            }
        }

        return null;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item::class !== Rack::class || !static::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item::class !== Rack::class || !static::canView()) {
            return false;
        }

        $floorplan = self::getFromRack($item->getID());

        if ($floorplan === null) {
            echo "<div class='center p-3'>";
            echo "<p>" . __('Nenhuma planta de rede vinculada a este rack ainda.') . "</p>";
            if (static::canCreate()) {
                echo "<a class='btn btn-primary' href='" . $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/glpinetworkmap'
                    . "/front/floorplan.form.php?racks_id=" . $item->getID() . "'>"
                    . "<i class='ti ti-plus'></i> " . __('Cadastrar planta para este rack') . "</a>";
            }
            echo "</div>";
            return true;
        }

        $can_edit = static::canUpdate();

        if ($floorplan->getImageUrl() === '') {
            echo "<div class='alert alert-warning m-3'>";
            echo __('Esta planta ainda não tem uma imagem.');
            if ($can_edit) {
                echo " <a href='" . $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/glpinetworkmap' . "/front/floorplan.form.php?id="
                    . $floorplan->getID() . "'>" . __('Editar planta') . "</a>";
            }
            echo "</div>";
            return true;
        }

        self::renderMapWidget($floorplan, $can_edit);
        return true;
    }

    /**
     * HTML/CSS/JS do visualizador de mapa (Leaflet + toolbar), compartilhado
     * entre front/map.php (página standalone) e a aba deste itemtype no
     * Rack - para não duplicar o bootstrap do mapa nos dois lugares.
     */
    public static function renderMapWidget(self $floorplan, bool $can_edit): void
    {
        $plugin_web_dir = $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/glpinetworkmap';
        $ID = $floorplan->getID();

        // Leaflet (JS/CSS) não é carregado aqui: o próprio núcleo do GLPI já
        // inclui a biblioteca em toda página autenticada (ver
        // Html::header() -> Html::requireJs('leaflet')), então o global `L`
        // já existe quando esta aba é aberta - repetir o include aqui só
        // duplicaria a requisição sem necessidade.
        echo "<link rel='stylesheet' href='{$plugin_web_dir}/public/css/map.css?v=" . filemtime(__DIR__ . '/../public/css/map.css') . "'>";
        ?>
        <div class="pulse-map-card">
            <div class="pulse-map-card-header">
                <div>
                    <h3 class="pulse-map-card-title">
                        <?php echo __('Mapa da infraestrutura'); ?>
                        <span class="pulse-badge-new"><?php echo __('NOVO'); ?></span>
                    </h3>
                    <p class="pulse-map-card-subtitle">
                        <?php echo __('Visualize a planta do ambiente e os dispositivos relacionados a este rack.'); ?>
                    </p>
                </div>
                <?php if ($can_edit) { ?>
                    <a href="<?php echo $plugin_web_dir; ?>/front/floorplan.form.php?id=<?php echo $ID; ?>"
                       class="btn btn-sm btn-outline-secondary pulse-map-upload-btn">
                        <i class="ti ti-upload"></i> <?php echo __('Upload / Alterar Planta'); ?>
                    </a>
                <?php } ?>
            </div>

            <div class="pulse-map-layout">
                <div class="pulse-map-main">
                    <div id="pulse-network-map"
                         data-floorplan-id="<?php echo $ID; ?>"
                         data-image-url="<?php echo htmlspecialchars($floorplan->getImageUrl()); ?>"
                         data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
                         data-ajax-url="<?php echo $plugin_web_dir; ?>/ajax/marker.php"
                         data-csrf-token="<?php echo Session::getNewCSRFToken(); ?>"
                         data-i18n='<?php echo json_encode([
                             'chooseCategory' => __('Categoria'),
                             'chooseAsset'    => __('Ativo'),
                             'pointName'      => __('Nome do ponto'),
                             'searchItem'     => __('Buscar por nome ou nº de inventário...'),
                             'save'           => __('Salvar ponto'),
                             'cancel'         => __('Cancelar'),
                             'delete'         => __('Excluir ponto'),
                             'confirmDelete'  => __('Remover este ponto do mapa?'),
                             'noResults'      => __('Nenhum resultado'),
                             'viewDetails'    => __('Ver detalhes'),
                             'noImage'        => __('Esta planta ainda não tem uma imagem cadastrada.'),
                             'legend'         => __('Legenda'),
                             'filters'        => __('Filtros'),
                             'addPoint'       => __('Adicionar ponto'),
                             'movePoint'      => __('Mover'),
                             'editPoint'      => __('Editar'),
                             'clickToPlace'   => __('Clique no mapa para posicionar o ponto...'),
                             'clickToMove'    => __('Clique no mapa para mover o ponto...'),
                         ]); ?>'
                    ></div>
                    <?php if ($can_edit) { ?>
                        <div class="pulse-map-footer">
                            <button type="button" id="pulse-add-point-btn" class="btn btn-sm btn-primary">
                                <i class="ti ti-plus"></i> <?php echo __('Adicionar ponto'); ?>
                            </button>
                        </div>
                    <?php } ?>
                </div>
                <aside class="pulse-map-sidebar" id="pulse-map-sidebar"></aside>
            </div>
        </div>

        <script>
            window.PULSE_NETWORKMAP_CATEGORIES = <?php echo json_encode(PluginGlpinetworkmapMarker::getCategories()); ?>;
        </script>
        <script>
            <?php
            // Conteúdo do map.js embutido inline (em vez de <script src>)
            // só aqui, na aba do rack: este HTML chega ao navegador via
            // AJAX (ajax/common.tabs.php), injetado no DOM bem depois do
            // carregamento inicial da página. Scripts inline injetados
            // assim são reavaliados de forma confiável; um <script src>
            // dinâmico não tem essa garantia (o navegador pode buscá-lo de
            // forma assíncrona, fora de ordem). front/map.php (página cheia,
            // carregada do zero a cada visita) continua usando <script src>
            // normalmente - lá não há esse problema.
            echo file_get_contents(__DIR__ . '/../public/js/map.js');
            ?>
        </script>
        <?php
    }
}
