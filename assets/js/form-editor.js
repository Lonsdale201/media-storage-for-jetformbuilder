(function (wp, settings) {
  if (!wp || !settings || !wp.hooks) {
    return;
  }

  const { addFilter } = wp.hooks;
  const i18n = wp.i18n || {};
  const __ = i18n.__ || ((text) => text);
  const sprintf =
    typeof i18n.sprintf === 'function'
      ? i18n.sprintf
      : (template, ...args) => {
          if (!template) {
            return '';
          }

          let formatted = template;
          args.forEach((arg) => {
            formatted = formatted.replace('%s', arg);
          });

          return formatted;
        };
  const { useMemo } = wp.element || {};
  const { SelectControl, TextControl, Notice, FormTokenField } = wp.components || {};
  const jetHooks = window.JetFBHooks || {};
  const useMetaState = jetHooks.useMetaState;

  if (!useMetaState || !SelectControl || !TextControl || !FormTokenField) {
    return;
  }

  const labels = {
    title:
      (settings.labels && settings.labels.title) ||
      __('Media Storage', 'media-storage-for-jetformbuilder'),
    usageLabel:
      (settings.labels && settings.labels.usageLabel) ||
      __('Storage usage', 'media-storage-for-jetformbuilder'),
    usageDisabled:
      (settings.labels && settings.labels.usageDisabled) ||
      __('Do not use external storage', 'media-storage-for-jetformbuilder'),
    usageEnabled:
      (settings.labels && settings.labels.usageEnabled) ||
      __('Use external storage', 'media-storage-for-jetformbuilder'),
    folderLabel:
      (settings.labels && settings.labels.folderLabel) ||
      __('Folder route', 'media-storage-for-jetformbuilder'),
    folderHelp:
      (settings.labels && settings.labels.folderHelp) ||
      __('Type a custom path or keep "default" to use the automatic structure.', 'media-storage-for-jetformbuilder'),
    folderPlaceholder:
      (settings.labels && settings.labels.folderPlaceholder) || 'default',
    sizeLabel:
      (settings.labels && settings.labels.sizeLabel) ||
      __('Max file size (MB)', 'media-storage-for-jetformbuilder'),
    sizeHelp:
      (settings.labels && settings.labels.sizeHelp) ||
      __('Leave empty to inherit the global limit. Use 0 or -1 for unlimited uploads. Supports decimals like 1.5 or 0,5.', 'media-storage-for-jetformbuilder'),
    noProviders:
      (settings.labels && settings.labels.noProviders) ||
      __('No storage providers are enabled for this site.', 'media-storage-for-jetformbuilder'),
    deleteOriginalLabel:
      (settings.labels && settings.labels.deleteOriginalLabel) ||
      __('Delete original file', 'media-storage-for-jetformbuilder'),
    deleteOriginalInherit:
      (settings.labels && settings.labels.deleteOriginalInherit) ||
      __('Inherit global setting', 'media-storage-for-jetformbuilder'),
    deleteOriginalOn:
      (settings.labels && settings.labels.deleteOriginalOn) ||
      __('Yes — delete after sync', 'media-storage-for-jetformbuilder'),
    deleteOriginalOff:
      (settings.labels && settings.labels.deleteOriginalOff) ||
      __('No — keep local file', 'media-storage-for-jetformbuilder'),
    allowedTypesLabel:
      (settings.labels && settings.labels.allowedTypesLabel) ||
      __('Allowed file types', 'media-storage-for-jetformbuilder'),
    allowedTypesHelp:
      (settings.labels && settings.labels.allowedTypesHelp) ||
      __('Leave empty to inherit the global setting. Type a MIME type and press Enter.', 'media-storage-for-jetformbuilder'),
    allowedTypesHelpGlobal:
      (settings.labels && settings.labels.allowedTypesHelpGlobal) ||
      __('Current global setting: %s types selected.', 'media-storage-for-jetformbuilder'),
    allowedTypesHelpGlobalAll:
      (settings.labels && settings.labels.allowedTypesHelpGlobalAll) ||
      __('Current global setting: all types allowed.', 'media-storage-for-jetformbuilder'),
  };

  const blueprint = (() => {
    if (!settings.defaultState) {
      return { providers: {} };
    }

    try {
      return JSON.parse(settings.defaultState) || { providers: {} };
    } catch (e) {
      return { providers: {} };
    }
  })();

  const sanitizeSizeValue = (value) => {
    if (typeof value === 'undefined') {
      return null;
    }

    const prepared =
      typeof value === 'string'
        ? value.replace(',', '.').trim()
        : value === null
        ? null
        : value;

    if (prepared === null || prepared === '') {
      return null;
    }

    const parsed = parseFloat(prepared);

    if (Number.isNaN(parsed) || !Number.isFinite(parsed)) {
      return null;
    }

    if (parsed < 0) {
      return -1;
    }

    return parseFloat(parsed.toFixed(4));
  };

  const normalizeState = (value) => {
    const base = {
      migrated: !!blueprint.migrated,
      delete_original: null,
      max_filesize_mb:
        typeof blueprint.max_filesize_mb === 'number'
          ? blueprint.max_filesize_mb
          : null,
      allowed_file_types: null,
      providers: { ...(blueprint.providers || {}) },
    };

    if (value && typeof value === 'object') {
      if (typeof value.migrated !== 'undefined') {
        base.migrated = !!value.migrated;
      }

      if (Object.prototype.hasOwnProperty.call(value, 'delete_original')) {
        const raw = value.delete_original;
        base.delete_original =
          raw === null || typeof raw === 'undefined' ? null : !!raw;
      }

      if (Object.prototype.hasOwnProperty.call(value, 'max_filesize_mb')) {
        base.max_filesize_mb = sanitizeSizeValue(value.max_filesize_mb);
      }

      if (Object.prototype.hasOwnProperty.call(value, 'allowed_file_types')) {
        const raw = value.allowed_file_types;
        base.allowed_file_types =
          raw === null ? null : Array.isArray(raw) ? raw : null;
      }

      if (value.providers && typeof value.providers === 'object') {
        Object.keys(value.providers).forEach((key) => {
          const current = value.providers[key] || {};
          base.providers[key] = {
            ...(base.providers[key] || {}),
            ...current,
          };
        });
      }
    }

    return base;
  };

  const getAvailableProviders = () => settings.providers || [];

  const MediaStoragePanel = () => {
    const formatLimitValue = (value) => {
      if (typeof value !== 'number' || Number.isNaN(value)) {
        return value;
      }

      return parseFloat(value.toFixed(4)).toString();
    };

    const [state, setState] = useMetaState(
      settings.metaKey,
      settings.defaultState || '{}',
      []
    );
    const normalized = useMemo(() => normalizeState(state), [state]);
    const availableProviders = useMemo(getAvailableProviders, [settings.providers]);
    const folderPlaceholder = settings.folderTemplateDefault || labels.folderPlaceholder;
    const sizeInputValue =
      typeof normalized.max_filesize_mb === 'number'
        ? normalized.max_filesize_mb
        : '';
    const globalLimit =
      typeof settings.maxFilesizeDefault === 'number'
        ? settings.maxFilesizeDefault
        : 0;
    const sizeHelpText =
      globalLimit > 0 && labels.sizeHelpGlobal
        ? `${labels.sizeHelp} ${sprintf(
            labels.sizeHelpGlobal,
            formatLimitValue(globalLimit)
          )}`
        : globalLimit <= 0 && labels.sizeHelpGlobalUnlimited
        ? `${labels.sizeHelp} ${labels.sizeHelpGlobalUnlimited}`
        : labels.sizeHelp;

    const updateProvider = (providerId, patch) => {
      setState((current) => {
        const next = normalizeState(current);
        next.providers[providerId] = {
          ...(next.providers[providerId] || {
            mode: 'disabled',
            folder: labels.folderPlaceholder,
          }),
          ...patch,
        };
        next.migrated = true;
        return next;
      });
    };

    if (!availableProviders.length) {
      return wp.element.createElement(Notice, {
        status: 'info',
        isDismissible: false,
        children: labels.noProviders,
      });
    }

    const activeProviderId = (() => {
      const map = normalized.providers || {};
      const found = availableProviders.find((provider) => {
        const config = map[provider.id];
        return config && config.mode === 'enabled';
      });
      return found ? found.id : 'none';
    })();

    const activeConfig =
      activeProviderId !== 'none'
        ? normalized.providers[activeProviderId] || {
            mode: 'enabled',
            folder: labels.folderPlaceholder,
          }
        : null;

    const selectOptions = [
      { label: labels.usageDisabled, value: 'none' },
      ...availableProviders.map((provider) => ({
        label: provider.label,
        value: provider.id,
      })),
    ];

    const handleProviderSelect = (value) => {
      setState((current) => {
        const next = normalizeState(current);
        availableProviders.forEach((provider) => {
          const key = provider.id;
          const base = next.providers[key] || {
            folder: labels.folderPlaceholder,
          };
          next.providers[key] = {
            ...base,
            mode: key === value ? 'enabled' : 'disabled',
          };
          if (!next.providers[key].folder) {
            next.providers[key].folder = labels.folderPlaceholder;
          }
        });
        next.migrated = true;
        return next;
      });
    };

    const handleFolderChange = (value) => {
      if (activeProviderId === 'none') {
        return;
      }

      updateProvider(activeProviderId, {
        folder: value && value.trim() ? value.trim() : labels.folderPlaceholder,
      });
    };

    const handleSizeChange = (value) => {
      setState((current) => {
        const next = normalizeState(current);
        next.max_filesize_mb = sanitizeSizeValue(value);
        next.migrated = true;
        return next;
      });
    };

    const deleteOriginalValue =
      normalized.delete_original === null
        ? ''
        : normalized.delete_original
        ? 'yes'
        : 'no';

    const handleDeleteOriginalChange = (value) => {
      setState((current) => {
        const next = normalizeState(current);
        next.delete_original =
          value === '' ? null : value === 'yes';
        next.migrated = true;
        return next;
      });
    };

    const globalDeleteLabel = settings.deleteOriginalDefault
      ? labels.deleteOriginalOn
      : labels.deleteOriginalOff;
    const deleteOriginalHelp =
      deleteOriginalValue === ''
        ? sprintf(
            __('Current global setting: %s', 'media-storage-for-jetformbuilder'),
            globalDeleteLabel.toLowerCase()
          )
        : '';

    const mimeTypeSuggestions = settings.mimeTypeSuggestions || [];
    const allowedFileTypes = normalized.allowed_file_types;
    const tokenFieldValue = Array.isArray(allowedFileTypes)
      ? allowedFileTypes
      : [];

    const globalTypes = settings.globalAllowedFileTypes || [];
    let allowedTypesHelp = labels.allowedTypesHelp;
    if (!Array.isArray(allowedFileTypes)) {
      allowedTypesHelp += ' ' + (
        globalTypes.length
          ? sprintf(labels.allowedTypesHelpGlobal, String(globalTypes.length))
          : labels.allowedTypesHelpGlobalAll
      );
    }

    const handleAllowedTypesChange = (tokens) => {
      setState((current) => {
        const next = normalizeState(current);
        next.allowed_file_types = tokens.length ? tokens : null;
        next.migrated = true;
        return next;
      });
    };

    return wp.element.createElement(
      'div',
      { className: 'msjfb-media-storage-panel' },
      wp.element.createElement(SelectControl, {
        label: labels.usageLabel,
        value: activeProviderId,
        options: selectOptions,
        onChange: handleProviderSelect,
      }),
      activeProviderId !== 'none'
        ? wp.element.createElement(TextControl, {
            label: labels.folderLabel,
            help: labels.folderHelp,
            placeholder: folderPlaceholder,
            value: activeConfig?.folder || labels.folderPlaceholder,
            onChange: handleFolderChange,
          })
        : null,
      wp.element.createElement(SelectControl, {
        label: labels.deleteOriginalLabel,
        help: deleteOriginalHelp,
        value: deleteOriginalValue,
        options: [
          { label: labels.deleteOriginalInherit, value: '' },
          { label: labels.deleteOriginalOn, value: 'yes' },
          { label: labels.deleteOriginalOff, value: 'no' },
        ],
        onChange: handleDeleteOriginalChange,
      }),
      wp.element.createElement(TextControl, {
        label: labels.sizeLabel,
        help: sizeHelpText,
        type: 'number',
        min: -1,
        step: '0.1',
        inputMode: 'decimal',
        placeholder: '',
        value: sizeInputValue,
        onChange: handleSizeChange,
      }),
      wp.element.createElement(FormTokenField, {
        label: labels.allowedTypesLabel,
        value: tokenFieldValue,
        suggestions: mimeTypeSuggestions,
        onChange: handleAllowedTypesChange,
        tokenizeOnSpace: true,
        __experimentalExpandOnFocus: true,
        __experimentalShowHowTo: false,
      }),
      wp.element.createElement(
        'p',
        { className: 'msjfb-form-panel-help' },
        allowedTypesHelp
      )
    );
  };

  const panelDefinition = {
    base: {
      name: 'jf-media-storage-panel',
      title: labels.title,
    },
    settings: {
      icon: 'cloud',
      render: MediaStoragePanel,
    },
  };

  addFilter(
    'jet.fb.register.plugins',
    'media-storage-for-jetformbuilder/panel',
    (plugins = []) => {
      const exists = plugins.some(
        (plugin) =>
          plugin &&
          plugin.base &&
          plugin.base.name === panelDefinition.base.name
      );

      if (!exists) {
        plugins.push(panelDefinition);
      }

      return plugins;
    }
  );
})(window.wp, window.MSJFBFormSettings || {});
