(function () {
    'use strict';

    function boot() {
        var container = document.getElementById('pulse-network-map');
        if (!container || typeof L === 'undefined' || container.dataset.pulseInitialized === '1') {
            return;
        }
        container.dataset.pulseInitialized = '1';

        var config = {
            floorplanId: container.getAttribute('data-floorplan-id'),
            imageUrl:    container.getAttribute('data-image-url'),
            canEdit:     container.getAttribute('data-can-edit') === '1',
            ajaxUrl:     container.getAttribute('data-ajax-url'),
            csrfToken:   container.getAttribute('data-csrf-token'),
            i18n:        JSON.parse(container.getAttribute('data-i18n') || '{}'),
            categories:  window.PULSE_NETWORKMAP_CATEGORIES || {},
        };

        if (!config.imageUrl) {
            return;
        }

        var img = new Image();
        img.onload = function () {
            initMap(container, config, img.naturalWidth, img.naturalHeight);
        };
        img.onerror = function () {
            container.innerHTML = '<p class="text-danger p-3">' +
                (config.i18n.noImage || 'Erro ao carregar a imagem da planta.') + '</p>';
        };
        img.src = config.imageUrl;
    }

    // Este script tanto é carregado normalmente no <head>/<body> de
    // front/map.php (página cheia - "DOMContentLoaded" ainda não disparou)
    // quanto injetado via AJAX dentro da aba do Rack, bem depois do
    // carregamento inicial da página - nesse segundo caso o evento
    // "DOMContentLoaded" já disparou há muito tempo e nunca dispararia de
    // novo, deixando o mapa sempre em branco. Rodar direto quando o
    // documento já não estiver mais "loading" cobre os dois casos.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function initMap(container, config, width, height) {
        var bounds = [[0, 0], [height, width]];

        var map = L.map(container, {
            crs: L.CRS.Simple,
            minZoom: -4,
            maxZoom: 4,
            zoomControl: false,
            scrollWheelZoom: false,
        });

        L.imageOverlay(config.imageUrl, bounds).addTo(map);
        map.fitBounds(bounds);

        addMapControls(map, bounds, container);

        var addPointBtn = document.getElementById('pulse-add-point-btn');
        var armedForAdd = false;
        var pendingMove = null;

        var hintBanner = document.createElement('div');
        hintBanner.className = 'pulse-map-hint';
        hintBanner.hidden = true;
        container.appendChild(hintBanner);

        function updateHint() {
            if (pendingMove) {
                hintBanner.textContent = config.i18n.clickToMove || 'Clique no mapa para mover o ponto...';
                hintBanner.hidden = false;
            } else if (armedForAdd) {
                hintBanner.textContent = config.i18n.clickToPlace || 'Clique no mapa para posicionar o ponto...';
                hintBanner.hidden = false;
            } else {
                hintBanner.hidden = true;
            }
        }

        function setArmed(value) {
            armedForAdd = value;
            if (value) {
                pendingMove = null;
            }
            if (addPointBtn) {
                addPointBtn.classList.toggle('active', value);
            }
            container.classList.toggle('pulse-armed-cursor', value || !!pendingMove);
            updateHint();
        }

        function setPendingMove(value) {
            pendingMove = value;
            if (value) {
                armedForAdd = false;
                if (addPointBtn) {
                    addPointBtn.classList.remove('active');
                }
            }
            container.classList.toggle('pulse-armed-cursor', !!value);
            updateHint();
        }

        if (addPointBtn) {
            addPointBtn.addEventListener('click', function () {
                setArmed(!armedForAdd);
            });
        }

        // Um layerGroup por categoria: permite ligar/desligar a visibilidade
        // de cada categoria independentemente a partir do painel de filtros.
        var layersByCategory = {};
        Object.keys(config.categories).forEach(function (cat) {
            layersByCategory[cat] = L.layerGroup().addTo(map);
        });
        var markersById = {};

        var sidebar = document.getElementById('pulse-map-sidebar');
        if (sidebar) {
            buildSidebar(sidebar, config, layersByCategory, map);
        }

        function percentToLatLng(posX, posY) {
            // pos_x/pos_y são salvos como percentuais (0-100) com origem no
            // canto superior esquerdo da imagem, como coordenadas de tela
            // normais. O CRS.Simple do Leaflet cresce para cima (como
            // latitude), então a coordenada Y é invertida aqui na hora de
            // desenhar - e revertida de novo em latLngToPercent ao salvar.
            var x = (posX / 100) * width;
            var y = height - (posY / 100) * height;
            return L.latLng(y, x);
        }

        function latLngToPercent(latlng) {
            var x = (latlng.lng / width) * 100;
            var y = (1 - (latlng.lat / height)) * 100;
            return {
                x: Math.max(0, Math.min(100, x)),
                y: Math.max(0, Math.min(100, y)),
            };
        }

        function iconFor(category) {
            var def = config.categories[category] || {};
            var color = def.color || '#111827';
            var icon = def.icon || 'ti-map-pin';
            return L.divIcon({
                className: 'pulse-marker-icon-wrapper',
                html: '<i class="ti ' + icon + '" style="font-size:26px;color:' + color +
                    ';text-shadow:0 0 2px #fff,0 0 2px #fff,0 0 2px #fff;"></i>',
                iconSize: [26, 26],
                iconAnchor: [13, 26],
                popupAnchor: [0, -26],
            });
        }

        function popupContent(markerData) {
            var item = markerData.item;
            var categoryDef = config.categories[markerData.category] || {};
            var html = '<div class="pulse-marker-popup">';

            if (!item) {
                // Ponto manual (ex: "Ponto de rede"): sem ativo vinculado,
                // só o nome dado na hora de criar o ponto.
                html += '<h4>' + escapeHtml(markerData.label || '') + '</h4>';
                html += '<dl><dt>' + escapeHtml(categoryDef.label || '') + '</dt><dd></dd></dl>';
            } else {
                html += '<h4>' + escapeHtml(item.name || ('#' + item.items_id)) + '</h4>';
                html += '<dl>';
                html += '<dt>' + escapeHtml(categoryDef.label || item.type_name || '') + '</dt><dd></dd>';
                if (item.ip) {
                    html += '<dt>IP</dt><dd>' + escapeHtml(item.ip) + '</dd>';
                }
                if (item.location) {
                    html += '<dt>Local</dt><dd>' + escapeHtml(item.location) + '</dd>';
                }
                if (item.status) {
                    html += '<dt>Status</dt><dd>' + escapeHtml(item.status) + '</dd>';
                }
                if (item.inventory_number) {
                    html += '<dt>Nº inventário</dt><dd>' + escapeHtml(item.inventory_number) + '</dd>';
                }
                if (item.serial) {
                    html += '<dt>Nº série</dt><dd>' + escapeHtml(item.serial) + '</dd>';
                }
                html += '</dl>';
            }

            html += '<div class="pulse-marker-actions">';
            if (item) {
                html += '<a href="' + item.link + '" target="_blank" class="btn btn-sm btn-outline-primary">' +
                    (config.i18n.viewDetails || 'Ver detalhes') + '</a>';
            }
            if (config.canEdit) {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-edit-marker="' +
                    markerData.id + '">' + (config.i18n.editPoint || 'Editar') + '</button>';
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-move-marker="' +
                    markerData.id + '">' + (config.i18n.movePoint || 'Mover') + '</button>';
                html += '<button type="button" class="btn btn-sm btn-outline-danger" data-delete-marker="' +
                    markerData.id + '">' + (config.i18n.delete || 'Excluir') + '</button>';
            }
            html += '</div>';

            html += '</div>';
            return html;
        }

        function markerLabelText(markerData) {
            return markerData.item ? (markerData.item.name || '') : (markerData.label || '');
        }

        function renderMarker(markerData) {
            var latlng = percentToLatLng(markerData.pos_x, markerData.pos_y);
            var marker = L.marker(latlng, {
                icon: iconFor(markerData.category),
                // Marcadores não são arrastáveis por padrão: um simples
                // clique para abrir o popup facilmente se transforma num
                // "mini arrasto" (o mouse se move um ou dois pixels entre
                // pressionar e soltar) quando draggable:true, e o Leaflet
                // interpreta isso como o usuário movendo o ponto sem
                // querer. Reposicionar agora é uma ação explícita via botão
                // "Mover" no popup (ver map.on('click') mais abaixo).
                draggable: false,
            });

            var labelText = markerLabelText(markerData);
            if (labelText) {
                marker.bindTooltip(escapeHtml(labelText), {
                    permanent: true,
                    direction: 'bottom',
                    offset: [0, 2],
                    className: 'pulse-marker-label',
                });
            }

            marker.bindPopup(popupContent(markerData));

            marker.on('popupopen', function (e) {
                var popupEl = e.popup.getElement();
                var deleteBtn = popupEl.querySelector('[data-delete-marker]');
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', function () {
                        if (confirm(config.i18n.confirmDelete || 'Remover este ponto do mapa?')) {
                            deleteMarker(markerData.id, marker, markerData.category);
                        }
                    });
                }
                var moveBtn = popupEl.querySelector('[data-move-marker]');
                if (moveBtn) {
                    moveBtn.addEventListener('click', function () {
                        map.closePopup();
                        setPendingMove({ id: markerData.id, marker: marker });
                    });
                }
                var editBtn = popupEl.querySelector('[data-edit-marker]');
                if (editBtn) {
                    editBtn.addEventListener('click', function () {
                        map.closePopup();
                        openEditMarkerPopup(markerData, marker);
                    });
                }
            });

            var layer = layersByCategory[markerData.category];
            if (layer) {
                layer.addLayer(marker);
            } else {
                marker.addTo(map);
            }
            markersById[markerData.id] = marker;
        }

        function loadMarkers() {
            fetch(config.ajaxUrl + '?action=list&floorplans_id=' + encodeURIComponent(config.floorplanId))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    Object.keys(layersByCategory).forEach(function (cat) {
                        layersByCategory[cat].clearLayers();
                    });
                    markersById = {};
                    list.forEach(renderMarker);
                });
        }

        function postAction(action, params) {
            var body = new URLSearchParams(params);
            body.set('action', action);
            body.set('_glpi_csrf_token', config.csrfToken);
            return fetch(config.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then(function (r) { return r.json(); });
        }

        function moveMarker(id, x, y) {
            postAction('move', { id: id, pos_x: x, pos_y: y });
        }

        function deleteMarker(id, leafletMarker, category) {
            postAction('delete', { id: id }).then(function (res) {
                if (res.ok) {
                    var layer = layersByCategory[category];
                    if (layer) {
                        layer.removeLayer(leafletMarker);
                    } else {
                        map.removeLayer(leafletMarker);
                    }
                    delete markersById[id];
                }
            });
        }

        // --- Adição de novo ponto (via botão "Adicionar ponto") ---

        var pendingSelection = null;

        map.on('click', function (e) {
            if (pendingMove) {
                var move = pendingMove;
                setPendingMove(null);
                var pos = latLngToPercent(e.latlng);
                move.marker.setLatLng(e.latlng);
                moveMarker(move.id, pos.x, pos.y);
                return;
            }

            if (!armedForAdd) {
                return;
            }
            setArmed(false);
            openAddMarkerPopup(e.latlng);
        });

        function openAddMarkerPopup(latlng) {
            pendingSelection = null;

            var categoryKeys = Object.keys(config.categories);
            var selectHtml = '<select class="pulse-add-category">';
            categoryKeys.forEach(function (key) {
                selectHtml += '<option value="' + key + '">' + escapeHtml(config.categories[key].label) + '</option>';
            });
            selectHtml += '</select>';

            var formHtml = '' +
                '<div class="pulse-add-marker-form">' +
                '<label>' + (config.i18n.chooseCategory || 'Categoria') + '</label>' +
                selectHtml +
                '<div class="pulse-add-asset-picker">' +
                '<label>' + (config.i18n.searchItem || 'Buscar ativo...') + '</label>' +
                '<input type="text" class="pulse-add-search" autocomplete="off">' +
                '<div class="pulse-add-marker-results"></div>' +
                '<div class="pulse-add-marker-selected"></div>' +
                '</div>' +
                '<div class="pulse-add-label-picker" hidden>' +
                '<label>' + (config.i18n.pointName || 'Nome do ponto') + '</label>' +
                '<input type="text" class="pulse-add-label" autocomplete="off">' +
                '</div>' +
                '<div class="pulse-marker-actions">' +
                '<button type="button" class="btn btn-sm btn-primary pulse-add-confirm" disabled>' +
                (config.i18n.save || 'Salvar ponto') + '</button>' +
                '</div>' +
                '</div>';

            var popup = L.popup({ closeOnClick: false, minWidth: 220 })
                .setLatLng(latlng)
                .setContent(formHtml)
                .openOn(map);

            var el = popup.getElement();
            var categorySelect = el.querySelector('.pulse-add-category');
            var assetPicker = el.querySelector('.pulse-add-asset-picker');
            var labelPicker = el.querySelector('.pulse-add-label-picker');
            var searchInput = el.querySelector('.pulse-add-search');
            var labelInput = el.querySelector('.pulse-add-label');
            var resultsBox = el.querySelector('.pulse-add-marker-results');
            var selectedBox = el.querySelector('.pulse-add-marker-selected');
            var confirmBtn = el.querySelector('.pulse-add-confirm');

            function isManualCategory() {
                var def = config.categories[categorySelect.value] || {};
                return !def.itemtype;
            }

            function resetPickers() {
                pendingSelection = null;
                confirmBtn.disabled = true;
                selectedBox.style.display = 'none';
                selectedBox.textContent = '';
                searchInput.value = '';
                labelInput.value = '';
                resultsBox.innerHTML = '';
                resultsBox.style.display = 'none';

                if (isManualCategory()) {
                    assetPicker.hidden = true;
                    labelPicker.hidden = false;
                } else {
                    assetPicker.hidden = false;
                    labelPicker.hidden = true;
                }
            }

            resetPickers();
            categorySelect.addEventListener('change', resetPickers);

            labelInput.addEventListener('input', function () {
                confirmBtn.disabled = labelInput.value.trim() === '';
            });

            var searchTimer = null;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = searchInput.value;
                searchTimer = setTimeout(function () {
                    fetch(config.ajaxUrl + '?action=search_items&category=' + encodeURIComponent(categorySelect.value) +
                        '&q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (items) {
                            resultsBox.innerHTML = '';
                            if (!items.length) {
                                resultsBox.innerHTML = '<div>' + (config.i18n.noResults || 'Nenhum resultado') + '</div>';
                            } else {
                                items.forEach(function (item) {
                                    var row = document.createElement('div');
                                    row.textContent = item.text;
                                    row.addEventListener('click', function () {
                                        pendingSelection = { items_id: item.id, text: item.text };
                                        selectedBox.textContent = item.text;
                                        selectedBox.style.display = 'block';
                                        resultsBox.style.display = 'none';
                                        confirmBtn.disabled = false;
                                    });
                                    resultsBox.appendChild(row);
                                });
                            }
                            resultsBox.style.display = 'block';
                        });
                }, 250);
            });

            confirmBtn.addEventListener('click', function () {
                var pos = latLngToPercent(latlng);
                var params = {
                    floorplans_id: config.floorplanId,
                    category: categorySelect.value,
                    pos_x: pos.x,
                    pos_y: pos.y,
                };

                if (isManualCategory()) {
                    var name = labelInput.value.trim();
                    if (!name) {
                        return;
                    }
                    params.label = name;
                } else {
                    if (!pendingSelection) {
                        return;
                    }
                    params.items_id = pendingSelection.items_id;
                }

                postAction('add', params).then(function (res) {
                    map.closePopup(popup);
                    if (res && res.id) {
                        renderMarker(res);
                    }
                });
            });
        }

        /**
         * Popup de edição: troca a categoria e/ou o ativo vinculado (ou o
         * nome, pra pontos manuais) de um marcador já existente, sem mexer
         * na posição - mover é a ação "Mover" separada, de propósito, pra
         * não misturar reposicionar com trocar o que o ponto representa.
         */
        function openEditMarkerPopup(markerData, marker) {
            var categoryKeys = Object.keys(config.categories);
            var selectHtml = '<select class="pulse-add-category">';
            categoryKeys.forEach(function (key) {
                var selected = key === markerData.category ? ' selected' : '';
                selectHtml += '<option value="' + key + '"' + selected + '>' +
                    escapeHtml(config.categories[key].label) + '</option>';
            });
            selectHtml += '</select>';

            var formHtml = '' +
                '<div class="pulse-add-marker-form">' +
                '<label>' + (config.i18n.chooseCategory || 'Categoria') + '</label>' +
                selectHtml +
                '<div class="pulse-add-asset-picker">' +
                '<label>' + (config.i18n.searchItem || 'Buscar ativo...') + '</label>' +
                '<input type="text" class="pulse-add-search" autocomplete="off">' +
                '<div class="pulse-add-marker-results"></div>' +
                '<div class="pulse-add-marker-selected"></div>' +
                '</div>' +
                '<div class="pulse-add-label-picker" hidden>' +
                '<label>' + (config.i18n.pointName || 'Nome do ponto') + '</label>' +
                '<input type="text" class="pulse-add-label" autocomplete="off">' +
                '</div>' +
                '<div class="pulse-marker-actions">' +
                '<button type="button" class="btn btn-sm btn-primary pulse-add-confirm">' +
                (config.i18n.save || 'Salvar ponto') + '</button>' +
                '</div>' +
                '</div>';

            var popup = L.popup({ closeOnClick: false, minWidth: 220 })
                .setLatLng(marker.getLatLng())
                .setContent(formHtml)
                .openOn(map);

            var el = popup.getElement();
            var categorySelect = el.querySelector('.pulse-add-category');
            var assetPicker = el.querySelector('.pulse-add-asset-picker');
            var labelPicker = el.querySelector('.pulse-add-label-picker');
            var searchInput = el.querySelector('.pulse-add-search');
            var labelInput = el.querySelector('.pulse-add-label');
            var resultsBox = el.querySelector('.pulse-add-marker-results');
            var selectedBox = el.querySelector('.pulse-add-marker-selected');
            var confirmBtn = el.querySelector('.pulse-add-confirm');

            // Começa pré-preenchido com o que o ponto já tem hoje - dá pra
            // confirmar sem mexer em nada (só trocando a categoria, por
            // exemplo) ou buscar um ativo diferente / mudar o nome.
            var pendingSelection = markerData.item
                ? { items_id: markerData.item.items_id, text: markerData.item.name }
                : null;

            function isManualCategory() {
                var def = config.categories[categorySelect.value] || {};
                return !def.itemtype;
            }

            function applyPrefill() {
                if (isManualCategory()) {
                    assetPicker.hidden = true;
                    labelPicker.hidden = false;
                    labelInput.value = markerData.label || '';
                } else {
                    assetPicker.hidden = false;
                    labelPicker.hidden = true;
                    if (pendingSelection) {
                        selectedBox.textContent = pendingSelection.text;
                        selectedBox.style.display = 'block';
                    }
                }
            }

            applyPrefill();

            categorySelect.addEventListener('change', function () {
                pendingSelection = null;
                selectedBox.style.display = 'none';
                selectedBox.textContent = '';
                searchInput.value = '';
                labelInput.value = '';
                resultsBox.innerHTML = '';
                resultsBox.style.display = 'none';
                applyPrefill();
            });

            var searchTimer = null;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                var q = searchInput.value;
                searchTimer = setTimeout(function () {
                    fetch(config.ajaxUrl + '?action=search_items&category=' + encodeURIComponent(categorySelect.value) +
                        '&q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (items) {
                            resultsBox.innerHTML = '';
                            if (!items.length) {
                                resultsBox.innerHTML = '<div>' + (config.i18n.noResults || 'Nenhum resultado') + '</div>';
                            } else {
                                items.forEach(function (item) {
                                    var row = document.createElement('div');
                                    row.textContent = item.text;
                                    row.addEventListener('click', function () {
                                        pendingSelection = { items_id: item.id, text: item.text };
                                        selectedBox.textContent = item.text;
                                        selectedBox.style.display = 'block';
                                        resultsBox.style.display = 'none';
                                    });
                                    resultsBox.appendChild(row);
                                });
                            }
                            resultsBox.style.display = 'block';
                        });
                }, 250);
            });

            confirmBtn.addEventListener('click', function () {
                var params = { id: markerData.id, category: categorySelect.value };

                if (isManualCategory()) {
                    var name = labelInput.value.trim();
                    if (!name) {
                        return;
                    }
                    params.label = name;
                } else {
                    if (!pendingSelection) {
                        return;
                    }
                    params.items_id = pendingSelection.items_id;
                }

                postAction('edit', params).then(function (res) {
                    map.closePopup(popup);
                    if (res && res.id) {
                        var layer = layersByCategory[markerData.category];
                        if (layer) {
                            layer.removeLayer(marker);
                        } else {
                            map.removeLayer(marker);
                        }
                        delete markersById[markerData.id];
                        renderMarker(res);
                    }
                });
            });
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }

        loadMarkers();
    }

    /**
     * Controles customizados no canto superior direito do mapa: zoom +/-,
     * tela cheia (Fullscreen API nativa do navegador) e "reencaixar" a
     * planta na tela (equivalente a um reset de zoom/posição).
     */
    function addMapControls(map, bounds, mapEl) {
        var Controls = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var wrap = L.DomUtil.create('div', 'pulse-map-controls leaflet-bar');

                function makeButton(icon, title, onClick) {
                    var btn = L.DomUtil.create('a', '', wrap);
                    btn.href = '#';
                    btn.title = title;
                    btn.innerHTML = '<i class="ti ' + icon + '"></i>';
                    L.DomEvent.on(btn, 'click', function (e) {
                        L.DomEvent.stop(e);
                        onClick();
                    });
                    return btn;
                }

                makeButton('ti-plus', 'Zoom +', function () { map.zoomIn(); });
                makeButton('ti-minus', 'Zoom -', function () { map.zoomOut(); });
                makeButton('ti-maximize', 'Tela cheia', function () {
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    } else if (mapEl.requestFullscreen) {
                        mapEl.requestFullscreen();
                    }
                });
                makeButton('ti-refresh', 'Reencaixar planta', function () {
                    map.fitBounds(bounds);
                });

                L.DomEvent.disableClickPropagation(wrap);
                return wrap;
            },
        });

        map.addControl(new Controls());

        document.addEventListener('fullscreenchange', function () {
            setTimeout(function () { map.invalidateSize(); }, 50);
        });
    }

    /**
     * Painel lateral (coluna fixa ao lado do mapa, não sobreposto): duas
     * caixas, "Legenda" (ícone + cor + nome de cada categoria) e "Filtros"
     * (checkbox que liga/desliga o layerGroup daquela categoria no mapa).
     */
    function buildSidebar(sidebar, config, layersByCategory, map) {
        sidebar.innerHTML = '';

        var legendBox = document.createElement('div');
        legendBox.className = 'pulse-sidebar-box';
        var legendTitle = document.createElement('div');
        legendTitle.className = 'pulse-sidebar-box-title';
        legendTitle.textContent = config.i18n.legend || 'Legenda';
        legendBox.appendChild(legendTitle);

        var filterBox = document.createElement('div');
        filterBox.className = 'pulse-sidebar-box';
        var filterTitle = document.createElement('div');
        filterTitle.className = 'pulse-sidebar-box-title';
        filterTitle.textContent = config.i18n.filters || 'Filtros';
        filterBox.appendChild(filterTitle);

        Object.keys(config.categories).forEach(function (cat) {
            var def = config.categories[cat];

            var legendRow = document.createElement('div');
            legendRow.className = 'pulse-legend-row';
            var icon = document.createElement('i');
            icon.className = 'ti ' + (def.icon || 'ti-point-filled');
            icon.style.color = def.color || '#111827';
            var text = document.createElement('span');
            text.textContent = def.label;
            legendRow.appendChild(icon);
            legendRow.appendChild(text);
            legendBox.appendChild(legendRow);

            var filterRow = document.createElement('label');
            filterRow.className = 'pulse-filter-row';
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = true;
            checkbox.addEventListener('change', function () {
                var layer = layersByCategory[cat];
                if (!layer) {
                    return;
                }
                if (checkbox.checked) {
                    layer.addTo(map);
                } else {
                    layer.remove();
                }
            });
            var filterText = document.createElement('span');
            filterText.textContent = def.label;
            filterRow.appendChild(checkbox);
            filterRow.appendChild(filterText);
            filterBox.appendChild(filterRow);
        });

        sidebar.appendChild(legendBox);
        sidebar.appendChild(filterBox);
    }
})();
