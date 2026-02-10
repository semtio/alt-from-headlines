(function (wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel, PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost || {};
    const { PanelRow, CheckboxControl, Button, Notice } = wp.components;
    const { useState, useEffect, createElement, Fragment } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const domReady = wp.domReady;

    const headings = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    const AltFromHeadlinesPanel = function () {
        const [selectedHeadings, setSelectedHeadings] = useState({
            h1: false,
            h2: true,
            h3: true,
            h4: false,
            h5: false,
            h6: false,
        });
        const [overwriteExisting, setOverwriteExisting] = useState(true);
        const [processing, setProcessing] = useState(false);
        const [result, setResult] = useState(null);

        useEffect(function () {
            console.log('[ALT FROM HEADLINES] sidebar panel rendered');
        }, []);

        const blocks = useSelect(function (select) {
            return select('core/block-editor').getBlocks() || [];
        }, []);

        var blockDispatch = useDispatch('core/block-editor');
        var updateBlockAttributes = blockDispatch && blockDispatch.updateBlockAttributes ? blockDispatch.updateBlockAttributes : function () {};

        var extractHeadingText = function (block) {
            if (!block || !block.attributes) {
                return '';
            }
            var content = block.attributes.content || '';
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = content;
            return tempDiv.textContent || tempDiv.innerText || '';
        };

        var handleHeadingToggle = function (level) {
            setSelectedHeadings(function (prev) {
                var next = Object.assign({}, prev);
                next[level] = !prev[level];
                return next;
            });
        };

        var processBlocks = function () {
            console.log('[ALT FROM HEADLINES] processing blocks:', blocks.length);
            setProcessing(true);
            setResult(null);

            var processedCount = 0;
            var currentHeading = null;

            var processBlockList = function (list) {
                if (!Array.isArray(list)) {
                    return;
                }
                list.forEach(function (block) {
                    if (!block) {
                        return;
                    }

                    if (block.name === 'core/heading') {
                        var level = block.attributes ? block.attributes.level || 2 : 2;
                        var headingKey = 'h' + level;
                        if (selectedHeadings[headingKey]) {
                            currentHeading = extractHeadingText(block);
                        }
                    } else if (block.name === 'core/image' && currentHeading) {
                        var currentAlt = block.attributes ? block.attributes.alt || '' : '';
                        if (overwriteExisting || !currentAlt.trim()) {
                            if (block.clientId) {
                                updateBlockAttributes(block.clientId, {
                                    alt: currentHeading,
                                });
                                processedCount += 1;
                            }
                        }
                        currentHeading = null;
                    }

                    if (Array.isArray(block.innerBlocks) && block.innerBlocks.length) {
                        processBlockList(block.innerBlocks);
                    }
                });
            };

            processBlockList(blocks);

            setProcessing(false);
            setResult({
                type: 'success',
                count: processedCount,
            });

            setTimeout(function () {
                setResult(null);
            }, 5000);
        };

        var headingCheckboxes = headings.map(function (level) {
            return createElement(CheckboxControl, {
                key: level,
                label: level.toUpperCase(),
                checked: selectedHeadings[level],
                onChange: function () {
                    handleHeadingToggle(level);
                },
            });
        });

        var notice = result
            ? createElement(
                  'div',
                  { style: { marginTop: '12px' } },
                  createElement(Notice, { status: result.type, isDismissible: false },
                      createElement('strong', null, 'Обработано изображений: '),
                      createElement('span', null, ' ' + result.count)
                  )
              )
            : null;

        var panelInner = createElement(
            PanelRow,
            null,
            createElement(
                'div',
                { style: { width: '100%' } },
                createElement(
                    'p',
                    { style: { marginTop: 0, marginBottom: '12px', fontSize: '13px', color: '#757575' } },
                    'Выберите уровни заголовков:'
                ),
                headingCheckboxes,
                createElement(
                    'div',
                    { style: { marginTop: '16px', paddingTop: '12px', borderTop: '1px solid #ddd' } },
                    createElement(CheckboxControl, {
                        label: 'Перезаписывать существующие ALT',
                        checked: overwriteExisting,
                        onChange: setOverwriteExisting,
                        help: 'Если включено, ALT будет заменён даже если уже заполнен',
                    })
                ),
                createElement(
                    'div',
                    { style: { marginTop: '16px' } },
                    createElement(
                        Button,
                        {
                            variant: 'primary',
                            onClick: processBlocks,
                            isBusy: processing,
                            disabled: processing,
                            style: { width: '100%' },
                        },
                        processing ? 'Обработка...' : 'Проставить ALT'
                    )
                ),
                notice,
                createElement(
                    'p',
                    { style: { marginTop: '16px', fontSize: '12px', color: '#999', lineHeight: '1.5' } },
                    createElement('strong', null, 'Как работает:'),
                    createElement('br'),
                    'Плагин ищет выбранные заголовки (H1-H6), затем первое изображение после каждого заголовка и устанавливает ALT из текста заголовка.'
                )
            )
        );

        var PanelComponent = PluginDocumentSettingPanel || PluginSidebar;

        if (PanelComponent === PluginDocumentSettingPanel) {
            return createElement(
                PanelComponent,
                {
                    name: 'alt-from-headlines-panel',
                    title: 'ALT из заголовков',
                    className: 'alt-from-headlines-panel',
                    initialOpen: true,
                },
                panelInner
            );
        }

        // Fallback: отдельный сайдбар, если DocumentSettingPanel недоступен
        return createElement(
            Fragment,
            null,
            createElement(
                PluginSidebarMoreMenuItem,
                { target: 'alt-from-headlines-sidebar', icon: 'images-alt2' },
                'ALT из заголовков'
            ),
            createElement(
                PluginSidebar,
                {
                    name: 'alt-from-headlines-sidebar',
                    title: 'ALT из заголовков',
                    icon: 'images-alt2',
                },
                panelInner
            )
        );
    };

    domReady(function () {
        if (window.__altFromHeadlinesPanelRegistered) {
            return;
        }
        window.__altFromHeadlinesPanelRegistered = true;
        console.log('[ALT FROM HEADLINES] registerPlugin init');
        registerPlugin('alt-from-headlines', {
            render: AltFromHeadlinesPanel,
            icon: 'images-alt2',
        });
    });
})(window.wp);
