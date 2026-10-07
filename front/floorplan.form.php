<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$item = new PluginGlpinetworkmapFloorplan();
$ID       = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$racks_id = (int) ($_GET['racks_id'] ?? $_POST['racks_id'] ?? 0);

if (isset($_GET['delete']) && $ID > 0) {
    $item->check($ID, PURGE);
    $item->delete(['id' => $ID], true);
    Html::redirect('floorplan.php');
}

if (isset($_POST['add'])) {
    $item->check(-1, CREATE);

    if ($racks_id > 0) {
        $existing = PluginGlpinetworkmapFloorplan::getFromRack($racks_id);
        if ($existing !== null) {
            Html::redirect('floorplan.form.php?id=' . $existing->getID());
        }
    }

    $input = [
        'name'     => $_POST['name'] ?? '',
        'comment'  => $_POST['comment'] ?? '',
        'racks_id' => $racks_id,
    ];

    $image = plugin_glpinetworkmap_handle_upload();
    if ($image !== null) {
        $input['image'] = $image;
    }

    $newID = $item->add($input);
    if ($newID) {
        if ($racks_id > 0) {
            // Volta pra tela do rack, já na aba "Planta de rede" - é de lá
            // que este formulário foi aberto quando racks_id vem preenchido.
            Html::redirect($GLOBALS['CFG_GLPI']['root_doc'] . '/front/rack.form.php?id=' . $racks_id
                . '&forcetab=PluginGlpinetworkmapFloorplan$1');
        }
        Html::redirect('map.php?id=' . $newID);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($ID, UPDATE);

    $input = [
        'id'      => $ID,
        'name'    => $_POST['name'] ?? '',
        'comment' => $_POST['comment'] ?? '',
    ];

    $image = plugin_glpinetworkmap_handle_upload();
    if ($image !== null) {
        // Substitui o arquivo antigo para não acumular imagem órfã a cada edição.
        $item->getFromDB($ID);
        if (!empty($item->fields['image'])) {
            $old = PluginGlpinetworkmapFloorplan::getImageDir() . '/' . $item->fields['image'];
            if (is_file($old)) {
                @unlink($old);
            }
        }
        $input['image'] = $image;
    }

    $item->update($input);
    Html::redirect('floorplan.php');
} else {
    // Exibição do formulário
    if ($ID > 0) {
        $item->check($ID, READ);
        $item->getFromDB($ID);
    } else {
        $item->check(-1, CREATE);

        if ($racks_id > 0) {
            // Um rack só pode ter uma planta (ver PluginGlpinetworkmapFloorplan::getFromRack).
            // Sem esta checagem, visitar este link duas vezes (ex: aba
            // ainda mostrando "cadastrar" numa versão em cache da página)
            // cria uma segunda planta pro mesmo rack e quebra a aba.
            $existing = PluginGlpinetworkmapFloorplan::getFromRack($racks_id);
            if ($existing !== null) {
                Html::redirect('floorplan.form.php?id=' . $existing->getID());
            }
        }
    }

    Html::header(
        PluginGlpinetworkmapFloorplan::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginGlpinetworkmapFloorplan'
    );

    echo "<div class='center'>";
    echo "<form name='floorplan_form' method='post' enctype='multipart/form-data' action='floorplan.form.php'>";
    echo "<table class='tab_cadre_fixe'>";
    echo "<tr><th colspan='2'>" . ($ID > 0 ? __('Editar planta') : __('Nova planta')) . "</th></tr>";

    if ($racks_id > 0) {
        $rack = new Rack();
        $rack->getFromDB($racks_id);
        echo "<tr class='tab_bg_1'><td>" . __('Rack vinculado') . "</td><td>"
            . htmlspecialchars($rack->fields['name'] ?? ('#' . $racks_id)) . "</td></tr>";
        echo Html::hidden('racks_id', ['value' => $racks_id]);
    }

    echo "<tr class='tab_bg_1'><td>" . __('Nome') . "</td><td>";
    echo Html::input('name', ['value' => $item->fields['name'] ?? '', 'required' => 'required']);
    echo "</td></tr>";

    echo "<tr class='tab_bg_1'><td>" . __('Comentário') . "</td><td>";
    echo "<textarea name='comment' class='form-control' rows='3'>" . htmlspecialchars($item->fields['comment'] ?? '') . "</textarea>";
    echo "</td></tr>";

    echo "<tr class='tab_bg_1'><td>" . __('Imagem da planta') . "</td><td>";
    if (!empty($item->fields['image']) && $item->getImageUrl() !== '') {
        echo "<div class='mb-2'><img src='" . $item->getImageUrl() . "' style='max-width:300px;max-height:200px;' alt=''></div>";
        echo "<div class='mb-1 text-muted'>" . __('Envie um novo arquivo para substituir a imagem atual.') . "</div>";
    }
    echo "<input type='file' name='floorplan_image' accept='image/png,image/jpeg,image/webp,application/pdf'" . ($ID > 0 ? '' : " required") . ">";
    echo "<div class='mt-1 text-muted'>" . __('Aceita imagem (PNG/JPEG/WEBP) ou PDF - se for PDF, a primeira página é convertida em imagem automaticamente.') . "</div>";
    echo "</td></tr>";

    echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
    echo Html::hidden('id', ['value' => $ID]);
    echo Html::submit($ID > 0 ? __('Salvar') : __('Adicionar'), ['name' => $ID > 0 ? 'update' : 'add']);
    echo "</td></tr>";

    echo "</table>";
    Html::closeForm();
    echo "</div>";

    Html::footer();
}

/**
 * Processa o upload da imagem da planta, se houver um arquivo válido em
 * $_FILES. Retorna o nome do arquivo salvo (para gravar no campo `image`)
 * ou null quando não há upload nesta requisição (ex: edição só do nome).
 */
function plugin_glpinetworkmap_handle_upload(): ?string
{
    if (!isset($_FILES['floorplan_image']) || $_FILES['floorplan_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES['floorplan_image']['error'] !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect(__('Falha no upload da imagem.'), false, ERROR);
        return null;
    }

    $tmp_name = $_FILES['floorplan_image']['tmp_name'];
    $original = $_FILES['floorplan_image']['name'];
    $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        return plugin_glpinetworkmap_convert_pdf_to_png($tmp_name);
    }

    if (!Document::isImage($tmp_name)) {
        Session::addMessageAfterRedirect(__('O arquivo enviado não é uma imagem nem um PDF válido.'), false, ERROR);
        return null;
    }

    $filename = uniqid('floorplan_', true) . '.' . $ext;
    $dest     = PluginGlpinetworkmapFloorplan::getImageDir() . '/' . $filename;

    if (!move_uploaded_file($tmp_name, $dest)) {
        Session::addMessageAfterRedirect(__('Não foi possível salvar a imagem enviada.'), false, ERROR);
        return null;
    }

    return $filename;
}

/**
 * Converte a primeira página de um PDF em PNG via pdftoppm (poppler-utils,
 * instalado na imagem custom do container - ver ambiente/docker/Dockerfile.glpi).
 * Retorna o nome do arquivo PNG gerado, já salvo no diretório da planta, ou
 * null se a conversão falhar (ex: PDF corrompido/inválido).
 */
function plugin_glpinetworkmap_convert_pdf_to_png(string $tmp_pdf_path): ?string
{
    $filename_base = uniqid('floorplan_', true);
    $output_base   = PluginGlpinetworkmapFloorplan::getImageDir() . '/' . $filename_base;

    // -f 1 -l 1: só a 1a página. -r 150: resolução legível sem pesar demais.
    // -singlefile: gera <base>.png em vez de <base>-1.png.
    $cmd = sprintf(
        'pdftoppm -png -f 1 -l 1 -r 150 -singlefile %s %s 2>&1',
        escapeshellarg($tmp_pdf_path),
        escapeshellarg($output_base)
    );
    exec($cmd, $cmd_output, $return_code);

    $expected_file = $output_base . '.png';
    if ($return_code !== 0 || !is_file($expected_file)) {
        Session::addMessageAfterRedirect(__('Não foi possível converter o PDF em imagem.'), false, ERROR);
        return null;
    }

    return $filename_base . '.png';
}
