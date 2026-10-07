<?php

include('../../../inc/includes.php');

Session::checkLoginUser();
$floorplan = new PluginGlpinetworkmapFloorplan();
$floorplan->checkGlobal(READ);

Html::header(
    PluginGlpinetworkmapFloorplan::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginGlpinetworkmapFloorplan'
);

global $DB;

echo "<div class='center'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr class='tab_bg_1'><th colspan='5'>" . PluginGlpinetworkmapFloorplan::getTypeName(2) . "</th></tr>";

if (PluginGlpinetworkmapFloorplan::canCreate()) {
    echo "<tr class='tab_bg_1'><td colspan='5' class='right'>";
    echo "<a class='btn btn-primary' href='floorplan.form.php'><i class='ti ti-plus'></i> " . __('Nova planta') . "</a>";
    echo "</td></tr>";
}

echo "<tr class='tab_bg_2'>";
echo "<th>" . __('Imagem') . "</th>";
echo "<th>" . __('Nome') . "</th>";
echo "<th>" . __('Rack vinculado') . "</th>";
echo "<th>" . __('Comentário') . "</th>";
echo "<th>" . __('Ações') . "</th>";
echo "</tr>";

$rows = $DB->request([
    'FROM'  => $floorplan->getTable(),
    'ORDER' => 'name ASC',
]);

if (count($rows) === 0) {
    echo "<tr class='tab_bg_1'><td colspan='5' class='center'>" . __('Nenhuma planta cadastrada ainda.') . "</td></tr>";
}

foreach ($rows as $data) {
    $floorplan->getFromResultSet($data);
    echo "<tr class='tab_bg_1'>";

    echo "<td class='center'>";
    if ($floorplan->getImageUrl() !== '') {
        echo "<img src='" . $floorplan->getImageUrl() . "' style='max-width:120px;max-height:80px;' alt=''>";
    }
    echo "</td>";

    echo "<td><a href='map.php?id=" . $data['id'] . "'>" . htmlspecialchars($data['name']) . "</a></td>";

    echo "<td>";
    if (!empty($data['racks_id'])) {
        $rack = new Rack();
        if ($rack->getFromDB($data['racks_id'])) {
            echo "<a href='" . $GLOBALS['CFG_GLPI']['root_doc'] . "/front/rack.form.php?id=" . $data['racks_id']
                . "&forcetab=PluginGlpinetworkmapFloorplan\$1'>" . htmlspecialchars($rack->fields['name']) . "</a>";
        }
    } else {
        echo "<span class='text-muted'>" . __('Nenhum') . "</span>";
    }
    echo "</td>";

    echo "<td>" . htmlspecialchars($data['comment'] ?? '') . "</td>";

    echo "<td class='center'>";
    echo "<a href='map.php?id=" . $data['id'] . "' class='btn btn-sm btn-outline-secondary' title='" . __('Ver mapa') . "'><i class='ti ti-map'></i></a> ";
    if (PluginGlpinetworkmapFloorplan::canUpdate()) {
        echo "<a href='floorplan.form.php?id=" . $data['id'] . "' class='btn btn-sm btn-outline-secondary' title='" . __('Editar') . "'><i class='ti ti-pencil'></i></a> ";
    }
    if (PluginGlpinetworkmapFloorplan::canPurge()) {
        echo "<a href='floorplan.form.php?id=" . $data['id'] . "&delete=1' class='btn btn-sm btn-outline-danger' title='" . __('Excluir') . "' onclick=\"return confirm('" . __('Confirma a exclusão desta planta e de todos os seus pontos?') . "');\"><i class='ti ti-trash'></i></a>";
    }
    echo "</td>";

    echo "</tr>";
}

echo "</table>";
echo "</div>";

Html::footer();
