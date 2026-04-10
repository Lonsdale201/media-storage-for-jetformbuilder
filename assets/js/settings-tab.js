(function (wp) {
  if (!wp || !wp.hooks || !wp.i18n) {
    return;
  }

  const { addFilter } = wp.hooks;
  const { __ } = wp.i18n;
  const apiFetch = wp?.apiFetch;

  const PROVIDERS = [
    {
      key: "dropbox",
      title: __("Dropbox", "media-storage-for-jetformbuilder"),
      description: __("Mirror JetFormBuilder uploads into your Dropbox workspace via OAuth tokens.", "media-storage-for-jetformbuilder"),
      docs: "https://www.dropbox.com/developers/apps",
      badges: [__("OAuth", "media-storage-for-jetformbuilder"), __("Task attachments", "media-storage-for-jetformbuilder")],
      fields: [
        {
          field: "access_token",
          label: __("Access Token", "media-storage-for-jetformbuilder"),
          help: __("Generate a short-lived or permanent token inside the Dropbox App dashboard.", "media-storage-for-jetformbuilder"),
        },
        {
          field: "app_key",
          label: __("App Key", "media-storage-for-jetformbuilder"),
          help: __("Found under Settings → App key.", "media-storage-for-jetformbuilder"),
        },
        {
          field: "app_secret",
          label: __("App Secret", "media-storage-for-jetformbuilder"),
          help: __("Keep this private. Needed when you rotate tokens.", "media-storage-for-jetformbuilder"),
        },
        {
          field: "refresh_token",
          label: __("Refresh Token", "media-storage-for-jetformbuilder"),
          help: __("Use the Dropbox OAuth flow with token_access_type=offline to obtain a refresh token for auto-rotating access tokens.", "media-storage-for-jetformbuilder"),
        },
      ],
    },
    {
      key: "cloudflare",
      title: __("Cloudflare R2", "media-storage-for-jetformbuilder"),
      description: __("Use an S3-compatible endpoint to archive submissions in R2 buckets.", "media-storage-for-jetformbuilder"),
      docs: "https://developers.cloudflare.com/r2/",
      badges: [__("S3 API", "media-storage-for-jetformbuilder"), __("Pay-as-you-go", "media-storage-for-jetformbuilder")],
      fields: [
        { field: "account_id", label: __("Account ID", "media-storage-for-jetformbuilder"), help: __("You can copy it from the Cloudflare dashboard header.", "media-storage-for-jetformbuilder") },
        { field: "access_key", label: __("Access Key ID", "media-storage-for-jetformbuilder"), help: __("The public key generated for R2 API tokens.", "media-storage-for-jetformbuilder") },
        { field: "secret_key", label: __("Secret Access Key", "media-storage-for-jetformbuilder"), help: __("Pairs with the Access Key ID.", "media-storage-for-jetformbuilder") },
        { field: "bucket", label: __("Bucket", "media-storage-for-jetformbuilder"), help: __("Destination bucket name (must already exist).", "media-storage-for-jetformbuilder") },
        { field: "region", label: __("Region", "media-storage-for-jetformbuilder"), help: __("Custom endpoint region, e.g. auto or EU.", "media-storage-for-jetformbuilder") },
      ],
    },
    {
      key: "gdrive",
      title: __("Google Drive", "media-storage-for-jetformbuilder"),
      description: __("Deliver uploads to Drive with your own OAuth client.", "media-storage-for-jetformbuilder"),
      docs: "https://console.cloud.google.com/apis/credentials",
      badges: [__("Drive API", "media-storage-for-jetformbuilder"), __("Scopes", "media-storage-for-jetformbuilder")],
      fields: [
        { field: "client_id", label: __("Client ID", "media-storage-for-jetformbuilder"), help: __("OAuth client ID with Drive.file scope.", "media-storage-for-jetformbuilder") },
        { field: "client_secret", label: __("Client Secret", "media-storage-for-jetformbuilder"), help: __("Never share it publicly.", "media-storage-for-jetformbuilder") },
        { field: "refresh_token", label: __("Refresh Token", "media-storage-for-jetformbuilder"), help: __("Generate it once via your OAuth consent screen.", "media-storage-for-jetformbuilder") },
        { field: "folder_id", label: __("Root folder", "media-storage-for-jetformbuilder"), help: __("Folder name or Drive folder ID. Leave empty to use the Drive root.", "media-storage-for-jetformbuilder") },
      ],
    },
  ];

  const defaultProviders = () => {
    const mapped = PROVIDERS.reduce((acc, provider) => {
      acc[provider.key] = provider.fields.reduce(
        (fields, item) => ({ ...fields, [item.field]: "" }),
        { enabled: false }
      );
      return acc;
    }, {});

    if (mapped.dropbox) {
      mapped.dropbox.access_token_expires_at = "";
    }

    if (mapped.gdrive) {
      mapped.gdrive.access_token = "";
      mapped.gdrive.access_token_expires_at = "";
    }

    return mapped;
  };

  const MediaStorageSettingsTab = {
    name: "media-storage-settings-tab",
    props: {
      incoming: {
        type: Object,
        default() {
          return {};
        },
      },
    },
    data() {
      const normalizeIncomingSize = (value) => {
        if (value === null || typeof value === "undefined" || value === "") {
          return 0;
        }

        if (typeof value === "number" && Number.isFinite(value)) {
          return value < 0 ? -1 : parseFloat(value.toFixed(4));
        }

        if (typeof value === "string") {
          const normalized = value.replace(",", ".").trim();
          if (normalized === "") {
            return 0;
          }

          const parsed = parseFloat(normalized);
          if (Number.isNaN(parsed) || !Number.isFinite(parsed)) {
            return 0;
          }

          return parsed < 0 ? -1 : parseFloat(parsed.toFixed(4));
        }

        return 0;
      };

      return {
        current: {
          delete_original: !!this.incoming?.delete_original,
          folder_template:
            this.incoming?.folder_template ||
            "JetFormBuilder/%formname%/%currentdate%",
          max_filesize_mb: normalizeIncomingSize(
            this.incoming?.max_filesize_mb
          ),
          allowed_file_types: Array.isArray(this.incoming?.allowed_file_types)
            ? this.incoming.allowed_file_types
            : [],
          fileTypeSearch: "",
          debug_enabled: !!this.incoming?.debug_enabled,
          providers: Object.assign(
            defaultProviders(),
            this.incoming?.providers || {}
          ),
        },
        oauth: {
          dropbox: {
            busy: false,
            message: "",
            error: "",
          },
          gdrive: {
            busy: false,
            message: "",
            error: "",
          },
        },
      };
    },
    created() {
      this.handleDropboxMessage = this.handleDropboxMessage.bind(this);
      this.handleGdriveMessage = this.handleGdriveMessage.bind(this);
      window.addEventListener("message", this.handleDropboxMessage);
      window.addEventListener("message", this.handleGdriveMessage);
    },
    beforeDestroy() {
      window.removeEventListener("message", this.handleDropboxMessage);
      window.removeEventListener("message", this.handleGdriveMessage);
    },
    methods: {
      getRequestOnSave() {
        const { fileTypeSearch, ...data } = this.current;
        return { data };
      },
      parseFilesizeValue(value, allowNegative = true) {
        if (value === null || typeof value === "undefined" || value === "") {
          return 0;
        }

        let prepared = value;

        if (typeof prepared === "string") {
          prepared = prepared.replace(",", ".").trim();

          if (prepared === "") {
            return 0;
          }
        }

        const parsed = parseFloat(prepared);

        if (Number.isNaN(parsed) || !Number.isFinite(parsed)) {
          return 0;
        }

        if (parsed < 0) {
          return allowNegative ? -1 : 0;
        }

        return parseFloat(parsed.toFixed(4));
      },
      toggleFileType(mime, checked) {
        const current = [...this.current.allowed_file_types];

        if (checked && !current.includes(mime)) {
          current.push(mime);
        } else if (!checked) {
          const idx = current.indexOf(mime);
          if (idx > -1) {
            current.splice(idx, 1);
          }
        }

        this.$set(this.current, "allowed_file_types", current);
      },
      renderFileTypePicker(h) {
        const allGroups =
          (window.MSJFBSettingsMeta &&
            window.MSJFBSettingsMeta.file_type_options) ||
          {};
        const categoryLabels = {
          image: __("Images", "media-storage-for-jetformbuilder"),
          audio: __("Audio", "media-storage-for-jetformbuilder"),
          video: __("Video", "media-storage-for-jetformbuilder"),
          application: __(
            "Documents & Archives",
            "media-storage-for-jetformbuilder"
          ),
          text: __("Text", "media-storage-for-jetformbuilder"),
        };

        const query = (this.current.fileTypeSearch || "").toLowerCase();

        const filteredGroups = {};
        for (const cat of Object.keys(allGroups)) {
          const items = query
            ? allGroups[cat].filter(
                (opt) =>
                  opt.label.toLowerCase().includes(query) ||
                  opt.value.toLowerCase().includes(query)
              )
            : allGroups[cat];
          if (items.length) {
            filteredGroups[cat] = items;
          }
        }

        const selectedCount = this.current.allowed_file_types.length;
        const badge = selectedCount
          ? h(
              "span",
              { class: "msjfb-filetype-badge" },
              selectedCount +
                " " +
                __("selected", "media-storage-for-jetformbuilder")
            )
          : null;

        const searchInput = h("input", {
          class: "msjfb-filetype-picker__search",
          attrs: {
            type: "text",
            placeholder: __(
              "Search file types\u2026",
              "media-storage-for-jetformbuilder"
            ),
          },
          domProps: { value: this.current.fileTypeSearch },
          on: {
            input: (e) =>
              this.$set(this.current, "fileTypeSearch", e.target.value),
          },
        });

        const groupKeys = Object.keys(filteredGroups);
        let listContent;

        if (!groupKeys.length) {
          listContent = h(
            "div",
            { class: "msjfb-filetype-picker__empty" },
            __("No file types match your search.", "media-storage-for-jetformbuilder")
          );
        } else {
          listContent = groupKeys.map((category) =>
            h("div", { class: "msjfb-filetype-picker__group" }, [
              h(
                "div",
                { class: "msjfb-filetype-picker__group-title" },
                categoryLabels[category] || category
              ),
              h(
                "div",
                { class: "msjfb-filetype-picker__items" },
                filteredGroups[category].map((opt) =>
                  h("label", { class: "msjfb-filetype-picker__item" }, [
                    h("input", {
                      attrs: { type: "checkbox" },
                      domProps: {
                        checked:
                          this.current.allowed_file_types.includes(opt.value),
                      },
                      on: {
                        change: (e) =>
                          this.toggleFileType(opt.value, e.target.checked),
                      },
                    }),
                    h("span", null, opt.label),
                  ])
                )
              ),
            ])
          );
        }

        return h(
          "div",
          { class: "cx-vui-component cx-vui-component--equalwidth" },
          [
            h("div", { class: "cx-vui-component__meta" }, [
              h(
                "label",
                { class: "cx-vui-component__label" },
                [
                  __("Allowed file types", "media-storage-for-jetformbuilder"),
                  badge,
                ].filter(Boolean)
              ),
              h(
                "div",
                { class: "cx-vui-component__desc" },
                __(
                  "Only selected file types will be synced to providers. Leave empty to allow all types.",
                  "media-storage-for-jetformbuilder"
                )
              ),
            ]),
            h("div", { class: "cx-vui-component__control" }, [
              h("div", { class: "msjfb-filetype-picker" }, [
                searchInput,
                h(
                  "div",
                  { class: "msjfb-filetype-picker__list" },
                  Array.isArray(listContent) ? listContent : [listContent]
                ),
              ]),
            ]),
          ]
        );
      },
      updateProviderField(provider, field, value) {
        const updated = Object.assign({}, this.current.providers[provider], {
          [field]: value,
        });

        this.$set(this.current.providers, provider, updated);
      },
      renderTextInput(h, providerKey, field, label, help, disabled = false) {
        const wrapperClasses = ["equalwidth"];
        if (disabled) {
          wrapperClasses.push("msjfb-input-disabled");
        }

        return h("cx-vui-input", {
          attrs: {
            label,
            description: help,
            size: "fullwidth",
            "wrapper-css": wrapperClasses,
            disabled,
          },
          model: {
            value: this.current.providers[providerKey][field] || "",
            callback: (value) => this.updateProviderField(providerKey, field, value),
            expression: `current.providers.${providerKey}.${field}`,
          },
        });
      },
      renderBadges(h, badges = []) {
        if (!badges.length) {
          return null;
        }

        return h(
          "div",
          { class: "msjfb-pill-group" },
          badges.map((text) =>
            h("span", { class: "msjfb-pill" }, text)
          )
        );
      },
      renderProviderSection(h, providerConfig) {
        const key = providerConfig.key;
        const isEnabled = !!this.current.providers[key].enabled;

        const switcher = h("cx-vui-switcher", {
          attrs: {
            label: __("Enable", "media-storage-for-jetformbuilder"),
            "wrapper-css": ["equalwidth"],
          },
          model: {
            value: isEnabled,
            callback: (value) =>
              this.updateProviderField(key, "enabled", !!value),
            expression: `current.providers.${key}.enabled`,
          },
        });

        const docLink = providerConfig.docs
          ? h(
              "a",
              {
                attrs: {
                  href: providerConfig.docs,
                  target: "_blank",
                  rel: "noopener noreferrer",
                },
                class: "msjfb-doc-link",
              },
              [
                __("Read setup guide", "media-storage-for-jetformbuilder"),
                h("span", { class: "dashicons dashicons-external" }),
              ]
            )
          : null;

        const inputs = providerConfig.fields.map((field) =>
          this.renderTextInput(
            h,
            key,
            field.field,
            field.label,
            field.help,
            !isEnabled
          )
        );

        const credentialsPanel = h(
          "cx-vui-panel",
          {
            attrs: {
              title: __("Credentials", "media-storage-for-jetformbuilder"),
            },
          },
          [
            switcher,
            ...inputs,
            h(
              "div",
              { class: "msjfb-provider-card__footer" },
              providerConfig.footer ||
                __(
                  "Disabled providers keep the form logic local until you are ready.",
                  "media-storage-for-jetformbuilder"
                )
            ),
          ]
        );

        let extras = null;
        if (key === "dropbox") {
          extras = this.renderDropboxActions(h, isEnabled);
        } else if (key === "gdrive") {
          extras = this.renderGdriveActions(h, isEnabled);
        }

        return h("div", { class: "msjfb-section msjfb-section--provider" }, [
          h("div", { class: "msjfb-section__headline" }, [
            h("div", { class: "msjfb-section__title-group" }, [
              h("h3", null, providerConfig.title),
              this.renderBadges(h, providerConfig.badges),
            ]),
            docLink,
          ]),
          h(
            "p",
            { class: "msjfb-provider-description" },
            providerConfig.description
          ),
          credentialsPanel,
          extras,
        ]);
      },
      renderDropboxActions(h, isEnabled) {
        const callbackUrl =
          (window.MSJFBSettingsMeta &&
            window.MSJFBSettingsMeta.dropbox &&
            window.MSJFBSettingsMeta.dropbox.callback_url) ||
          "";
        const state = this.oauth.dropbox;
        const disabled = !isEnabled || !this.canGenerateDropboxToken();

        const saveNotice = h(
          "p",
          { class: "msjfb-oauth-note msjfb-oauth-note--warn" },
          __(
            "Important: Save your settings first, then click Generate Refresh Token.",
            "media-storage-for-jetformbuilder"
          )
        );

        const button = h(
          "cx-vui-button",
          {
            class: [
              "cx-vui-button",
              "cx-vui-button--style-accent",
              "cx-vui-button--size-default",
            ],
            attrs: {
              "button-style": "accent",
              loading: state.busy,
              disabled,
            },
            on: {
              click: () => this.handleDropboxGenerate(),
            },
          },
          [
            h(
              "span",
              {
                slot: "label",
              },
              __("Generate Refresh Token", "media-storage-for-jetformbuilder")
            ),
          ]
        );

        const notice =
          callbackUrl &&
          h(
            "p",
            { class: "msjfb-oauth-note" },
            [
              __(
                "Add this redirect URI to your Dropbox app if you plan to use automatic refresh:",
                "media-storage-for-jetformbuilder"
              ),
              h("code", null, callbackUrl),
            ]
          );

        let feedback = null;
        if (state.error) {
          feedback = h(
            "p",
            { class: "msjfb-oauth-feedback msjfb-oauth-feedback--error" },
            state.error
          );
        } else if (state.message) {
          feedback = h(
            "p",
            { class: "msjfb-oauth-feedback msjfb-oauth-feedback--success" },
            state.message
          );
        }

        return h(
          "div",
          { class: "msjfb-oauth-actions" },
          [saveNotice, button, notice, feedback].filter(Boolean)
        );
      },
      canGenerateDropboxToken() {
        const provider = this.current.providers.dropbox || {};
        return (
          (provider.app_key || "").trim() !== "" &&
          (provider.app_secret || "").trim() !== ""
        );
      },
      handleDropboxGenerate() {
        if (this.oauth.dropbox.busy) {
          return;
        }

        if (!apiFetch) {
          this.oauth.dropbox.error = __(
            "The WordPress REST API is unavailable on this screen.",
            "media-storage-for-jetformbuilder"
          );
          return;
        }

        if (!this.canGenerateDropboxToken()) {
          this.oauth.dropbox.error = __(
            "Enter the App key and secret first.",
            "media-storage-for-jetformbuilder"
          );
          return;
        }

        this.oauth.dropbox.busy = true;
        this.oauth.dropbox.error = "";
        this.oauth.dropbox.message = "";

        const authorizePath =
          (window.MSJFBSettingsMeta &&
            window.MSJFBSettingsMeta.dropbox &&
            window.MSJFBSettingsMeta.dropbox.authorize_path) ||
          "/msjfb/v1/dropbox/authorize";

        apiFetch({
          path: authorizePath,
          method: "POST",
        })
          .then((response) => {
            this.oauth.dropbox.busy = false;

            if (!response || !response.authorize_url) {
              this.oauth.dropbox.error = __(
                "Could not start the Dropbox authorization flow.",
                "media-storage-for-jetformbuilder"
              );
              return;
            }

            const popup = window.open(
              response.authorize_url,
              "msjfbDropboxOAuth",
              "width=600,height=720"
            );

            if (!popup) {
              this.oauth.dropbox.error = __(
                "Your browser blocked the popup window. Please allow popups for this page and retry.",
                "media-storage-for-jetformbuilder"
              );
              return;
            }

            this.oauth.dropbox.message = __(
              "Continue the Dropbox authorization in the popup window.",
              "media-storage-for-jetformbuilder"
            );
          })
          .catch((error) => {
            this.oauth.dropbox.busy = false;
            this.oauth.dropbox.error =
              (error && error.message) ||
              __("Unexpected error while contacting the server.", "media-storage-for-jetformbuilder");
          });
      },
      handleDropboxMessage(event) {
        const origin = window.location.origin;
        if (origin && event.origin !== origin) {
          return;
        }

        const payload = event.data || {};

        if (!payload || payload.source !== "msjfb-dropbox") {
          return;
        }

        this.oauth.dropbox.busy = false;

        if (payload.status === "success" && payload.tokens) {
          if (payload.tokens.access_token) {
            this.updateProviderField(
              "dropbox",
              "access_token",
              payload.tokens.access_token
            );
          }

          if (payload.tokens.refresh_token) {
            this.updateProviderField(
              "dropbox",
              "refresh_token",
              payload.tokens.refresh_token
            );
          }

          if (payload.tokens.access_token_expires_at) {
            this.updateProviderField(
              "dropbox",
              "access_token_expires_at",
              payload.tokens.access_token_expires_at
            );
          }

          this.oauth.dropbox.message =
            payload.message ||
            __(
              "Dropbox tokens saved. You may now close the popup.",
              "media-storage-for-jetformbuilder"
            );
          this.oauth.dropbox.error = "";
        } else {
          this.oauth.dropbox.error =
            payload.message ||
            __("Dropbox authorization failed.", "media-storage-for-jetformbuilder");
          this.oauth.dropbox.message = "";
        }
      },
      // ── Google Drive OAuth ──
      renderGdriveActions(h, isEnabled) {
        const callbackUrl =
          (window.MSJFBSettingsMeta &&
            window.MSJFBSettingsMeta.gdrive &&
            window.MSJFBSettingsMeta.gdrive.callback_url) ||
          "";
        const state = this.oauth.gdrive;
        const disabled = !isEnabled || !this.canGenerateGdriveToken();
        const button = h(
          "cx-vui-button",
          {
            class: [
              "cx-vui-button",
              "cx-vui-button--style-accent",
              "cx-vui-button--size-default",
            ],
            attrs: {
              "button-style": "accent",
              loading: state.busy,
              disabled,
            },
            on: {
              click: () => this.handleGdriveGenerate(),
            },
          },
          [
            h(
              "span",
              { slot: "label" },
              __("Generate Refresh Token", "media-storage-for-jetformbuilder")
            ),
          ]
        );

        const saveNotice = h(
          "p",
          { class: "msjfb-oauth-note msjfb-oauth-note--warn" },
          __(
            "Important: Save your settings first, then click Generate Refresh Token.",
            "media-storage-for-jetformbuilder"
          )
        );

        const notice =
          callbackUrl &&
          h("p", { class: "msjfb-oauth-note" }, [
            __(
              "Add this redirect URI to your Google Cloud Console (Authorized redirect URIs):",
              "media-storage-for-jetformbuilder"
            ),
            h("code", null, callbackUrl),
          ]);

        let feedback = null;
        if (state.error) {
          feedback = h(
            "p",
            { class: "msjfb-oauth-feedback msjfb-oauth-feedback--error" },
            state.error
          );
        } else if (state.message) {
          feedback = h(
            "p",
            { class: "msjfb-oauth-feedback msjfb-oauth-feedback--success" },
            state.message
          );
        }

        return h(
          "div",
          { class: "msjfb-oauth-actions" },
          [saveNotice, button, notice, feedback].filter(Boolean)
        );
      },
      canGenerateGdriveToken() {
        const provider = this.current.providers.gdrive || {};
        return (
          (provider.client_id || "").trim() !== "" &&
          (provider.client_secret || "").trim() !== ""
        );
      },
      handleGdriveGenerate() {
        if (this.oauth.gdrive.busy) {
          return;
        }

        if (!apiFetch) {
          this.oauth.gdrive.error = __(
            "The WordPress REST API is unavailable on this screen.",
            "media-storage-for-jetformbuilder"
          );
          return;
        }

        if (!this.canGenerateGdriveToken()) {
          this.oauth.gdrive.error = __(
            "Enter the Client ID and Secret first.",
            "media-storage-for-jetformbuilder"
          );
          return;
        }

        this.oauth.gdrive.busy = true;
        this.oauth.gdrive.error = "";
        this.oauth.gdrive.message = "";

        const authorizePath =
          (window.MSJFBSettingsMeta &&
            window.MSJFBSettingsMeta.gdrive &&
            window.MSJFBSettingsMeta.gdrive.authorize_path) ||
          "/msjfb/v1/gdrive/authorize";

        apiFetch({
          path: authorizePath,
          method: "POST",
        })
          .then((response) => {
            this.oauth.gdrive.busy = false;

            if (!response || !response.authorize_url) {
              this.oauth.gdrive.error = __(
                "Could not start the Google authorization flow.",
                "media-storage-for-jetformbuilder"
              );
              return;
            }

            const popup = window.open(
              response.authorize_url,
              "msjfbGdriveOAuth",
              "width=600,height=720"
            );

            if (!popup) {
              this.oauth.gdrive.error = __(
                "Your browser blocked the popup window. Please allow popups for this page and retry.",
                "media-storage-for-jetformbuilder"
              );
              return;
            }

            this.oauth.gdrive.message = __(
              "Continue the Google authorization in the popup window.",
              "media-storage-for-jetformbuilder"
            );
          })
          .catch((error) => {
            this.oauth.gdrive.busy = false;
            this.oauth.gdrive.error =
              (error && error.message) ||
              __(
                "Unexpected error while contacting the server.",
                "media-storage-for-jetformbuilder"
              );
          });
      },
      handleGdriveMessage(event) {
        const origin = window.location.origin;
        if (origin && event.origin !== origin) {
          return;
        }

        const payload = event.data || {};

        if (!payload || payload.source !== "msjfb-gdrive") {
          return;
        }

        this.oauth.gdrive.busy = false;

        if (payload.status === "success" && payload.tokens) {
          if (payload.tokens.access_token) {
            this.updateProviderField(
              "gdrive",
              "access_token",
              payload.tokens.access_token
            );
          }

          if (payload.tokens.refresh_token) {
            this.updateProviderField(
              "gdrive",
              "refresh_token",
              payload.tokens.refresh_token
            );
          }

          if (payload.tokens.access_token_expires_at) {
            this.updateProviderField(
              "gdrive",
              "access_token_expires_at",
              payload.tokens.access_token_expires_at
            );
          }

          this.oauth.gdrive.message =
            payload.message ||
            __(
              "Google Drive tokens saved. You may now close the popup.",
              "media-storage-for-jetformbuilder"
            );
          this.oauth.gdrive.error = "";
        } else {
          this.oauth.gdrive.error =
            payload.message ||
            __(
              "Google Drive authorization failed.",
              "media-storage-for-jetformbuilder"
            );
          this.oauth.gdrive.message = "";
        }
      },
    },
    render(h) {
      const deleteOriginal = h("cx-vui-switcher", {
        attrs: {
          label: __("Delete original file", "media-storage-for-jetformbuilder"),
          description: __(
            "Remove the JetFormBuilder copy after a successful sync.",
            "media-storage-for-jetformbuilder"
          ),
          "wrapper-css": ["equalwidth"],
        },
        model: {
          value: !!this.current.delete_original,
          callback: (value) =>
            this.$set(this.current, "delete_original", !!value),
          expression: "current.delete_original",
        },
      });

      const generalSection = h("div", { class: "msjfb-section" }, [
        h("div", { class: "msjfb-section__headline" }, [
          h(
            "h3",
            null,
            __("General", "media-storage-for-jetformbuilder")
          ),
          h(
            "a",
            {
              attrs: {
                href: "https://github.com/Lonsdale201/media-storage-for-jetformbuilder",
                target: "_blank",
                rel: "noopener noreferrer",
              },
            },
            __("Project page", "media-storage-for-jetformbuilder")
          ),
        ]),
        h(
          "p",
          { class: "msjfb-general-note" },
          __(
            "Each provider inherits the settings defined here. Toggle a provider on only when its credentials are ready.",
            "media-storage-for-jetformbuilder"
          )
        ),
        deleteOriginal,
        h("cx-vui-input", {
          attrs: {
            label: __(
              "Default folder structure",
              "media-storage-for-jetformbuilder"
            ),
            description: __(
              "Use macros like %formid%, %formname%, %currentdate%, %currentyear%, %currentmonth%, %currentday%, %fieldslug%.",
              "media-storage-for-jetformbuilder"
            ),
            size: "fullwidth",
            "wrapper-css": ["equalwidth"],
          },
          model: {
            value: this.current.folder_template,
            callback: (value) =>
              this.$set(this.current, "folder_template", value),
            expression: "current.folder_template",
          },
        }),
        h("cx-vui-input", {
          attrs: {
            label: __("Max file size (MB)", "media-storage-for-jetformbuilder"),
            description: __(
              "Leave empty to inherit the global limit. Use 0 or -1 for unlimited uploads. Supports decimals like 1.5 or 0,5.",
              "media-storage-for-jetformbuilder"
            ),
            type: "number",
            min: -1,
            step: "0.1",
            inputmode: "decimal",
            size: "fullwidth",
            "wrapper-css": ["equalwidth"],
          },
          model: {
            value: this.current.max_filesize_mb,
            callback: (value) =>
              this.$set(
                this.current,
                "max_filesize_mb",
                this.parseFilesizeValue(value)
              ),
            expression: "current.max_filesize_mb",
          },
        }),
        this.renderFileTypePicker(h),
        h("cx-vui-switcher", {
          attrs: {
            label: __("Enable debug logs", "media-storage-for-jetformbuilder"),
            description: __(
              "When enabled, each upload attempt will be logged to the PHP error log.",
              "media-storage-for-jetformbuilder"
            ),
            "wrapper-css": ["equalwidth"],
          },
          model: {
            value: !!this.current.debug_enabled,
            callback: (value) =>
              this.$set(this.current, "debug_enabled", !!value),
            expression: "current.debug_enabled",
          },
        }),
      ]);

      const providerSections = PROVIDERS.map((provider) =>
        this.renderProviderSection(h, provider)
      );

      return h("div", { class: "msjfb-settings" }, [
        generalSection,
        ...providerSections,
      ]);
    },
  };

  const tabDefinition = {
    title: __("Media Storage", "media-storage-for-jetformbuilder"),
    component: MediaStorageSettingsTab,
  };

  addFilter(
    "jet.fb.register.settings-page.tabs",
    "media-storage-for-jetformbuilder/settings-tab",
    (tabs) => {
      tabs.push(tabDefinition);
      return tabs;
    }
  );
})(window.wp);
