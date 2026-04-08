function exifReloadDebug(...args) {
  if (typeof localStorage !== 'undefined' && localStorage.getItem('hnz_exif_debug') === '1') {
    console.log('[hnzio/exif-import]', ...args);
  }
}

function makeExifReloadField() {
  return {
    props: {
      label: String,
      help: String,
      disabled: Boolean,
      buttonLabel: {
        type: String,
        default: 'EXIF neu laden'
      },
      theme: {
        type: String,
        default: 'positive'
      }
    },

    data() {
      return {
        loading: false,
        error: null
      };
    },

    methods: {
      async resolveIds() {
        const view = this.$view ? await this.$api.get(this.$view.path + '/json').catch(() => null) : null;
        const model = view?.model || {};

        let pageId =
          (typeof model.id === 'string' && !model.id.includes(':') ? model.id : null) ||
          model.pageId || model.parentId || model.parentid || model.parent || null;
        if (!pageId && model.page) {
          pageId = typeof model.page === 'string' ? model.page : (model.page?.id || null);
        }

        let filename = model.filename || model.name || null;
        let fileUuid = model.uuid || null;

        try {
          const path = (this.$view?.path || window.location.pathname || '').replace(/^\/+|\/+$/g, '');
          const match = path.match(/pages\/(.+?)\/files\/([^/]+)/);
          if (match) {
            if (!pageId) pageId = decodeURIComponent(match[1]);
            if (!filename) filename = decodeURIComponent(match[2]);
          }
        } catch (error) {
          // ignore
        }

        if (!fileUuid && pageId && filename) {
          const file = await this.$api
            .get(`pages/${encodeURIComponent(pageId)}/files/${encodeURIComponent(filename)}?select=${encodeURIComponent('uuid,filename')}`)
            .catch(() => null);
          if (file?.uuid) {
            fileUuid = file.uuid;
          }
        }

        return {
          pageId: pageId || null,
          filename: filename || null,
          fileUuid: fileUuid || null
        };
      },

      async reloadExif() {
        if (this.loading || this.disabled) {
          return;
        }

        this.error = null;
        this.loading = true;

        try {
          const ids = await this.resolveIds();
          exifReloadDebug('resolved ids', ids);

          if (!ids.pageId || !ids.filename) {
            throw new Error('Seite oder Datei konnten im Panel nicht ermittelt werden.');
          }

          const response = await this.$api.post('exif-import/reload', ids);
          if (!response?.ok) {
            throw new Error(response?.message || 'EXIF-Import fehlgeschlagen.');
          }

          this.$panel.notification.success(response.message || 'EXIF-Daten wurden neu eingelesen.');
          window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
          const message = error?.message || 'EXIF-Import fehlgeschlagen.';
          this.error = message;
          this.$panel.notification.error(message);
        } finally {
          this.loading = false;
        }
      }
    },

    template: `
      <k-field :label="label" :help="help" :disabled="disabled">
        <k-button
          :theme="theme"
          icon="refresh"
          variant="filled"
          :disabled="loading || disabled"
          @click="reloadExif"
        >
          {{ loading ? 'Lade EXIF …' : buttonLabel }}
        </k-button>
        <k-text v-if="error" theme="negative" style="margin-top:.5rem">{{ error }}</k-text>
      </k-field>
    `
  };
}

panel.plugin('hnzio/exif-import', {
  fields: {
    'exif-reload': makeExifReloadField()
  }
});
