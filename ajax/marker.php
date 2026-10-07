<?php

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

if (!PluginGlpinetworkmapFloorplan::canView()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'list':
        plugin_glpinetworkmap_action_list();
        break;

    case 'search_items':
        plugin_glpinetworkmap_action_search_items();
        break;

    case 'add':
        plugin_glpinetworkmap_action_add();
        break;

    case 'edit':
        plugin_glpinetworkmap_action_edit();
        break;

    case 'move':
        plugin_glpinetworkmap_action_move();
        break;

    case 'delete':
        plugin_glpinetworkmap_action_delete();
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
}

/**
 * Monta o payload de um marcador para o front (list/add): dados da linha +
 * resumo do ativo vinculado (null para "Ponto de rede" manual, que não tem
 * itemtype nenhum - nesse caso o front usa só o `label`).
 */
function plugin_glpinetworkmap_build_marker_payload(array $row): array
{
    $summary = null;
    if (!empty($row['itemtype']) && !empty($row['items_id'])) {
        $summary = PluginGlpinetworkmapMarker::getItemSummary($row['itemtype'], (int) $row['items_id']);
    }

    return [
        'id'       => (int) $row['id'],
        'pos_x'    => (float) $row['pos_x'],
        'pos_y'    => (float) $row['pos_y'],
        'category' => $row['category'],
        'itemtype' => $row['itemtype'],
        'items_id' => (int) $row['items_id'],
        'label'    => $row['label'],
        'item'     => $summary,
    ];
}

function plugin_glpinetworkmap_action_list(): void
{
    global $DB;

    $floorplans_id = (int) ($_GET['floorplans_id'] ?? 0);
    if (!$floorplans_id) {
        echo json_encode([]);
        return;
    }

    $rows = $DB->request([
        'FROM'  => 'glpi_plugin_glpinetworkmap_markers',
        'WHERE' => ['plugin_glpinetworkmap_floorplans_id' => $floorplans_id],
    ]);

    $result = [];
    foreach ($rows as $row) {
        $result[] = plugin_glpinetworkmap_build_marker_payload($row);
    }

    echo json_encode($result);
}

function plugin_glpinetworkmap_action_search_items(): void
{
    $category = $_GET['category'] ?? '';
    $query    = trim($_GET['q'] ?? '');

    $itemtype = PluginGlpinetworkmapMarker::getItemtypeForCategory($category);
    if ($itemtype === null) {
        http_response_code(400);
        echo json_encode(['error' => 'category not searchable']);
        return;
    }

    $item = new $itemtype();
    $table = $item->getTable();

    $where = getEntitiesRestrictCriteria($table, '', '', true);
    if ($query !== '') {
        // Busca por nome OU número de inventário (`otherserial`) - em campo,
        // o técnico geralmente tem a etiqueta de inventário do ativo em mãos,
        // não necessariamente o nome cadastrado no GLPI.
        $where[] = [
            'OR' => [
                'name'        => ['LIKE', '%' . $query . '%'],
                'otherserial' => ['LIKE', '%' . $query . '%'],
            ],
        ];
    }

    global $DB;
    $rows = $DB->request([
        'SELECT' => ['id', 'name', 'otherserial'],
        'FROM'   => $table,
        'WHERE'  => $where,
        'ORDER'  => 'name ASC',
        'LIMIT'  => 20,
    ]);

    $result = [];
    foreach ($rows as $row) {
        $text = $row['name'] ?: ('#' . $row['id']);
        if (!empty($row['otherserial'])) {
            $text .= ' (' . $row['otherserial'] . ')';
        }
        $result[] = ['id' => (int) $row['id'], 'text' => $text];
    }

    echo json_encode($result);
}

/**
 * Valida category/items_id/label vindos do POST e resolve o itemtype real
 * (a partir da categoria, nunca do cliente direto - evita que um POST
 * forjado vincule o marcador a um itemtype arbitrário do GLPI). Usado tanto
 * por 'add' quanto por 'edit', que compartilham exatamente essa validação.
 * Em caso de erro, já escreve a resposta JSON e retorna null.
 */
function plugin_glpinetworkmap_resolve_marker_input(): ?array
{
    $category = $_POST['category'] ?? '';
    if (!PluginGlpinetworkmapMarker::isCategoryValid($category)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid category']);
        return null;
    }

    $itemtype = PluginGlpinetworkmapMarker::getItemtypeForCategory($category);
    $items_id = (int) ($_POST['items_id'] ?? 0);
    $label    = trim($_POST['label'] ?? '');

    if ($itemtype === null) {
        // "Ponto de rede": sem ativo vinculado, só precisa de um nome.
        if ($label === '') {
            http_response_code(400);
            echo json_encode(['error' => 'label required for manual point']);
            return null;
        }
        $itemtype = '';
        $items_id = 0;
    } elseif ($items_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'items_id required for this category']);
        return null;
    }

    return ['category' => $category, 'itemtype' => $itemtype, 'items_id' => $items_id, 'label' => $label];
}

function plugin_glpinetworkmap_action_add(): void
{
    // Sem checagem manual de CSRF aqui: o CheckCsrfListener do GLPI 11 já
    // valida (e consome) o `_glpi_csrf_token` do corpo do POST no nível do
    // kernel, antes deste script rodar - request inválido nem chega até
    // aqui (o listener lança AccessDeniedHttpException). Uma segunda
    // chamada a Session::validateCSRF() aqui sempre falharia, porque o
    // token já foi consumido pelo listener.
    if (!PluginGlpinetworkmapFloorplan::canUpdate()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    $resolved = plugin_glpinetworkmap_resolve_marker_input();
    if ($resolved === null) {
        return;
    }

    $marker = new PluginGlpinetworkmapMarker();
    $newID = $marker->add([
        'plugin_glpinetworkmap_floorplans_id' => (int) ($_POST['floorplans_id'] ?? 0),
        'category'                            => $resolved['category'],
        'itemtype'                            => $resolved['itemtype'],
        'items_id'                            => $resolved['items_id'],
        'pos_x'                               => (float) ($_POST['pos_x'] ?? 0),
        'pos_y'                               => (float) ($_POST['pos_y'] ?? 0),
        'label'                               => $resolved['label'],
    ]);

    if (!$newID) {
        http_response_code(500);
        echo json_encode(['error' => 'could not create marker']);
        return;
    }

    $marker->getFromDB($newID);
    echo json_encode(plugin_glpinetworkmap_build_marker_payload($marker->fields));
}

function plugin_glpinetworkmap_action_edit(): void
{
    // Sem checagem manual de CSRF aqui: ver comentário em plugin_glpinetworkmap_action_add().
    if (!PluginGlpinetworkmapFloorplan::canUpdate()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $marker = new PluginGlpinetworkmapMarker();
    if (!$id || !$marker->getFromDB($id)) {
        http_response_code(404);
        echo json_encode(['error' => 'marker not found']);
        return;
    }

    $resolved = plugin_glpinetworkmap_resolve_marker_input();
    if ($resolved === null) {
        return;
    }

    // Posição (pos_x/pos_y) não é alterada aqui de propósito - editar troca
    // categoria/ativo/nome do ponto; mover é uma ação separada (arrastar via
    // "Mover" no popup), pra não misturar as duas coisas num único POST.
    $ok = $marker->update([
        'id'       => $id,
        'category' => $resolved['category'],
        'itemtype' => $resolved['itemtype'],
        'items_id' => $resolved['items_id'],
        'label'    => $resolved['label'],
    ]);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'could not update marker']);
        return;
    }

    $marker->getFromDB($id);
    echo json_encode(plugin_glpinetworkmap_build_marker_payload($marker->fields));
}

function plugin_glpinetworkmap_action_move(): void
{
    // Sem checagem manual de CSRF aqui: o CheckCsrfListener do GLPI 11 já
    // valida (e consome) o `_glpi_csrf_token` do corpo do POST no nível do
    // kernel, antes deste script rodar - request inválido nem chega até
    // aqui (o listener lança AccessDeniedHttpException). Uma segunda
    // chamada a Session::validateCSRF() aqui sempre falharia, porque o
    // token já foi consumido pelo listener.
    if (!PluginGlpinetworkmapFloorplan::canUpdate()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    $marker = new PluginGlpinetworkmapMarker();
    $ok = $marker->update([
        'id'    => (int) ($_POST['id'] ?? 0),
        'pos_x' => (float) ($_POST['pos_x'] ?? 0),
        'pos_y' => (float) ($_POST['pos_y'] ?? 0),
    ]);

    echo json_encode(['ok' => (bool) $ok]);
}

function plugin_glpinetworkmap_action_delete(): void
{
    // Sem checagem manual de CSRF aqui: o CheckCsrfListener do GLPI 11 já
    // valida (e consome) o `_glpi_csrf_token` do corpo do POST no nível do
    // kernel, antes deste script rodar - request inválido nem chega até
    // aqui (o listener lança AccessDeniedHttpException). Uma segunda
    // chamada a Session::validateCSRF() aqui sempre falharia, porque o
    // token já foi consumido pelo listener.
    if (!PluginGlpinetworkmapFloorplan::canUpdate()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    $marker = new PluginGlpinetworkmapMarker();
    $ok = $marker->delete(['id' => (int) ($_POST['id'] ?? 0)], true);

    echo json_encode(['ok' => (bool) $ok]);
}
