<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$ID = (int) ($_GET['id'] ?? 0);

$floorplan = new PluginGlpinetworkmapFloorplan();
$floorplan->checkGlobal(READ);

if (!$ID || !$floorplan->getFromDB($ID)) {
    Html::displayNotFoundError();
}

$floorplan->check($ID, READ);
$can_edit = PluginGlpinetworkmapFloorplan::canUpdate();

global $CFG_GLPI;
$plugin_web_dir = $CFG_GLPI['root_doc'] . '/plugins/glpinetworkmap';

Html::header(
    $floorplan->fields['name'],
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginGlpinetworkmapFloorplan'
);

// Leaflet já vem carregado pelo próprio GLPI em toda página (ver
// Html::header() -> Html::requireJs('leaflet')) - sem include duplicado aqui.
echo "<link rel='stylesheet' href='{$plugin_web_dir}/public/css/map.css?v=" . filemtime(__DIR__ . '/../public/css/map.css') . "'>";
?>

<div class="pulse-map-card">
    <div class="pulse-map-card-header">
        <div>
            <h3 class="pulse-map-card-title">
                <?php echo htmlspecialchars($floorplan->fields['name']); ?>
            </h3>
            <p class="pulse-map-card-subtitle">
                <a href="floorplan.php"><i class="ti ti-arrow-left"></i> <?php echo __('Voltar para a lista de plantas'); ?></a>
            </p>
        </div>
        <?php if ($can_edit) { ?>
            <a href="floorplan.form.php?id=<?php echo $ID; ?>" class="btn btn-sm btn-outline-secondary pulse-map-upload-btn">
                <i class="ti ti-upload"></i> <?php echo __('Upload / Alterar Planta'); ?>
            </a>
        <?php } ?>
    </div>

    <?php if ($floorplan->getImageUrl() === '') { ?>
        <div class="alert alert-warning m-3">
            <?php echo __('Esta planta ainda não tem uma imagem. Edite a planta para enviar uma imagem.'); ?>
        </div>
    <?php } else { ?>
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
    <?php } ?>
</div>

<script>
    window.PULSE_NETWORKMAP_CATEGORIES = <?php echo json_encode(PluginGlpinetworkmapMarker::getCategories()); ?>;
</script>
<script src="<?php echo $plugin_web_dir; ?>/public/js/map.js?v=<?php echo filemtime(__DIR__ . '/../public/js/map.js'); ?>"></script>

<?php
Html::footer();
