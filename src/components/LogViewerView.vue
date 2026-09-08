<template>
  <k-panel-inside>
    <k-view class="k-log-viewer-view">
      <k-header>
        {{ $t("periscope.logs") }}
        <template #buttons>
          <k-button
            icon="copy"
            :disabled="filteredEntries.length === 0"
            @click="copyVisible"
          >
            {{ copiedVisible ? $t("periscope.copied") : $t("periscope.copyVisible") }}
          </k-button>
          <k-button
            icon="download"
            :link="downloadUrl"
            target="_blank"
            :disabled="!canDownload"
          >
            {{ $t("periscope.download") }}
          </k-button>
          <k-button
            icon="refresh"
            variant="filled"
            :disabled="!selectedId || loading"
            @click="reload"
          >
            {{ $t("periscope.refresh") }}
          </k-button>
        </template>
      </k-header>

      <k-box
        v-if="files.length === 0"
        theme="info"
        :text="$t('periscope.noFilesConfigured')"
      />

      <template v-else>
        <div class="k-log-viewer-toolbar">
          <k-select-field
            name="log-viewer-file"
            :label="$t('periscope.file')"
            class="k-log-viewer-field"
            :options="fileOptions"
            :value="selectedId"
            @input="selectedId = $event"
          />

          <k-select-field
            name="log-viewer-limit"
            :label="$t('periscope.entriesPerPage')"
            class="k-log-viewer-field k-log-viewer-field--narrow"
            :options="limitFieldOptions"
            :value="limit"
            @input="limit = Number($event)"
          />

          <label class="k-log-viewer-autorefresh">
            <input v-model="autoRefresh" type="checkbox" />
            {{ $t("periscope.autoRefresh") }}
          </label>
        </div>

        <p v-if="meta" class="k-log-viewer-meta">
          <template v-if="meta.exists && meta.readable">
            {{ $t("periscope.lastModified") }}: {{ formatDate(meta.modified) }} · {{ formatSize(meta.size) }}
          </template>
          <template v-else-if="meta.exists">
            <k-icon type="alert" /> {{ $t("periscope.fileNotReadable") }}
          </template>
          <template v-else>
            <k-icon type="alert" /> {{ $t("periscope.fileNotExists") }}
          </template>
        </p>

        <p v-if="meta && meta.minLevel" class="k-log-viewer-meta">
          <k-icon type="filter" /> {{ $t("periscope.minLevelNotice", { level: levelMeta(meta.minLevel).label }) }}
        </p>

        <k-box v-if="error" theme="negative" :text="error" />

        <div v-if="entries.length > 0" class="k-log-viewer-filters">
          <div class="k-log-viewer-levels">
            <button
              type="button"
              class="k-log-viewer-pill"
              :class="{ 'k-log-viewer-pill--active': !activeLevel }"
              @click="activeLevel = null"
            >
              {{ $t("periscope.all") }} <span class="k-log-viewer-pill-count">{{ entries.length }}</span>
            </button>
            <button
              v-for="stat in levelStats"
              :key="stat.level"
              type="button"
              class="k-log-viewer-pill"
              :class="{ 'k-log-viewer-pill--active': activeLevel === stat.level }"
              :data-theme="stat.theme"
              @click="activeLevel = activeLevel === stat.level ? null : stat.level"
            >
              <k-icon v-if="stat.icon" :type="stat.icon" />
              {{ stat.label }} <span class="k-log-viewer-pill-count">{{ stat.count }}</span>
            </button>
          </div>
          <k-search-input
            :value="search"
            :placeholder="$t('periscope.searchPlaceholder')"
            font="monospace"
            class="k-log-viewer-search"
            @input="search = $event"
          />
        </div>

        <div class="k-log-viewer-content-wrapper">
          <div ref="content" class="k-log-viewer-content" @scroll="onScroll">
            <button
              v-if="hasMore"
              type="button"
              class="k-log-viewer-more"
              :disabled="loadingMore"
              @click="loadMore"
            >
              {{ loadingMore ? $t("periscope.loading") : $t("periscope.loadOlder") }}
            </button>

            <p v-if="!loading && entries.length === 0" class="k-log-viewer-empty">
              {{ loading ? $t("periscope.loading") : $t("periscope.noEntries") }}
            </p>

            <div v-if="entries.length > 0 && filteredEntries.length === 0" class="k-log-viewer-empty">
              {{ $t("periscope.noMatches") }}
              <button type="button" class="k-log-viewer-reset" @click="resetFilters">
                {{ $t("periscope.resetFilters") }}
              </button>
            </div>

            <div
              v-for="entry in filteredEntries"
              :key="entry._id"
              class="k-log-viewer-entry"
              :class="{ 'k-log-viewer-entry--critical': entry.level === 'critical' }"
              :data-theme="levelMeta(entry.level).theme"
            >
              <k-icon
                v-if="levelMeta(entry.level).icon"
                :type="levelMeta(entry.level).icon"
                class="k-log-viewer-level-icon"
              />
              <button
                type="button"
                class="k-log-viewer-copy"
                :title="$t('periscope.copyEntry')"
                @click="copyEntry(entry)"
              >
                <k-icon :type="copiedId === entry._id ? 'check' : 'copy'" />
              </button>
              <div class="k-log-viewer-entry-body">
                <pre class="k-log-viewer-entry-text"><span v-if="entry.tsPart" class="k-log-viewer-ts">{{ entry.tsPart }}</span>{{ entry.headerRest }}<template v-if="!isCollapsible(entry) || isExpanded(entry)">{{ entry.tailLines }}</template></pre>
                <button
                  v-if="isCollapsible(entry)"
                  type="button"
                  class="k-log-viewer-toggle"
                  @click="toggleExpand(entry)"
                >
                  {{ isExpanded(entry) ? $t("periscope.collapse") : $t("periscope.showMoreLines", { count: entry.lineCount - 1 }) }}
                </button>
              </div>
            </div>
          </div>

          <button
            v-if="showJumpToLatest"
            type="button"
            class="k-log-viewer-jump"
            @click="jumpToLatest"
          >
            <k-icon type="angle-down" /> {{ $t("periscope.newEntries") }}
          </button>
        </div>
      </template>
    </k-view>
  </k-panel-inside>
</template>

<script>
const LEVEL_META = {
  critical: { theme: "negative", icon: "alert" },
  error: { theme: "negative", icon: "alert" },
  warning: { theme: "warning", icon: "bug" },
  notice: { theme: "notice", icon: "info" },
  info: { theme: "info", icon: "info" },
  debug: { theme: "passive", icon: "code" },
  plain: { theme: "passive", icon: null },
};

const COLLAPSE_THRESHOLD = 8;
// Erkennt die führende "[...]"-Kopfzeile (Zeitstempel), gleiche Idee wie
// isEntryHeader() serverseitig – hier nur fürs Absetzen der Optik, keine
// Zerlegungslogik.
const HEADER_PATTERN = /^(\[[^\]\n]*\])(.*)$/;

export default {
  name: "LogViewerView",

  props: {
    files: { type: Array, default: () => [] },
    defaultEntries: { type: Number, default: 200 },
  },

  data() {
    return {
      selectedId: this.files[0]?.id ?? null,
      limit: this.defaultEntries,
      limitOptions: [50, 100, 200, 500, 1000, 2000],
      entries: [],
      meta: null,
      hasMore: false,
      loading: false,
      loadingMore: false,
      error: null,
      autoRefresh: false,
      timer: null,
      copiedId: null,
      copiedTimer: null,
      copiedVisible: false,
      copiedVisibleTimer: null,
      nextEntryId: 0,
      requestId: 0,
      search: "",
      activeLevel: null,
      expandedIds: [],
      isNearBottom: true,
      showJumpToLatest: false,
    };
  },

  computed: {
    downloadUrl() {
      if (!this.selectedId) {
        return null;
      }
      return `${this.$panel.urls.api}/log-viewer/files/${this.selectedId}/download`;
    },

    canDownload() {
      return Boolean(this.meta?.exists && this.meta?.readable);
    },

    fileOptions() {
      return this.files.map((file) => ({ value: file.id, text: file.label }));
    },

    limitFieldOptions() {
      return this.limitOptions.map((option) => ({ value: option, text: String(option) }));
    },

    filteredEntries() {
      const search = this.search.trim().toLowerCase();

      return this.entries.filter((entry) => {
        if (this.activeLevel && entry.level !== this.activeLevel) {
          return false;
        }
        if (search && !entry.raw.toLowerCase().includes(search)) {
          return false;
        }
        return true;
      });
    },

    levelStats() {
      const counts = {};
      for (const entry of this.entries) {
        counts[entry.level] = (counts[entry.level] || 0) + 1;
      }

      const stats = [];
      for (const level of Object.keys(LEVEL_META)) {
        if (!counts[level]) {
          continue;
        }
        const meta = this.levelMeta(level);
        stats.push({
          level,
          label: meta.label,
          theme: meta.theme,
          icon: meta.icon,
          count: counts[level],
        });
      }

      return stats;
    },
  },

  watch: {
    selectedId() {
      this.resetFilters();
      this.reload();
    },
    limit() {
      this.reload();
    },
    autoRefresh(enabled) {
      this.setupTimer(enabled);
    },
  },

  mounted() {
    if (this.selectedId) {
      this.reload();
    }
  },

  // Vue 3 + Vue 2 Kompatibilität (Kirby-Panel-Version je nach Umgebung).
  beforeUnmount() {
    this.setupTimer(false);
    clearTimeout(this.copiedTimer);
    clearTimeout(this.copiedVisibleTimer);
  },
  beforeDestroy() {
    this.setupTimer(false);
    clearTimeout(this.copiedTimer);
    clearTimeout(this.copiedVisibleTimer);
  },

  methods: {
    levelMeta(level) {
      const key = LEVEL_META[level] ? level : "plain";
      return { ...LEVEL_META[key], label: this.$t(`periscope.level.${key}`) };
    },

    resetFilters() {
      this.search = "";
      this.activeLevel = null;
    },

    isCollapsible(entry) {
      return !this.search.trim() && entry.lineCount > COLLAPSE_THRESHOLD;
    },

    isExpanded(entry) {
      return this.expandedIds.includes(entry._id);
    },

    toggleExpand(entry) {
      this.expandedIds = this.isExpanded(entry)
        ? this.expandedIds.filter((id) => id !== entry._id)
        : [...this.expandedIds, entry._id];
    },

    reload() {
      this.load(true, false);
    },

    loadMore() {
      if (this.hasMore && !this.loadingMore) {
        this.load(false, false);
      }
    },

    onScroll() {
      const el = this.$refs.content;
      if (!el) {
        return;
      }
      this.isNearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
      if (this.isNearBottom) {
        this.showJumpToLatest = false;
      }
    },

    jumpToLatest() {
      this.showJumpToLatest = false;
      this.reload();
    },

    async load(reset, isAutoRefresh) {
      if (!this.selectedId) {
        return;
      }

      if (reset) {
        this.loading = true;
        this.error = null;
      } else {
        this.loadingMore = true;
      }

      const offset = reset ? 0 : this.entries.length;
      const requestId = ++this.requestId;

      try {
        const response = await this.$api.get(`log-viewer/files/${this.selectedId}`, {
          limit: this.limit,
          offset,
        });

        // Zwischenzeitlich hat sich Datei/Limit geändert – diese Antwort ist veraltet.
        if (requestId !== this.requestId) {
          return;
        }

        this.meta = response;
        const fetched = (response.entries || []).map((entry) => {
          const firstLineEnd = entry.raw.indexOf("\n");
          const firstLine = firstLineEnd === -1 ? entry.raw : entry.raw.slice(0, firstLineEnd);
          const tailLines = firstLineEnd === -1 ? "" : entry.raw.slice(firstLineEnd);
          const headerMatch = firstLine.match(HEADER_PATTERN);

          return {
            ...entry,
            _id: this.nextEntryId++,
            lineCount: entry.raw.split("\n").length,
            tsPart: headerMatch ? headerMatch[1] : "",
            headerRest: headerMatch ? headerMatch[2] : firstLine,
            tailLines,
          };
        });

        if (reset) {
          const wasNearBottom = this.isNearBottom;
          this.entries = fetched;
          this.hasMore = response.hasMore;
          this.expandedIds = [];

          if (!isAutoRefresh || wasNearBottom) {
            this.$nextTick(() => {
              const el = this.$refs.content;
              if (el) {
                el.scrollTop = el.scrollHeight;
              }
              this.isNearBottom = true;
              this.showJumpToLatest = false;
            });
          } else {
            this.showJumpToLatest = true;
          }
        } else {
          const el = this.$refs.content;
          const prevHeight = el?.scrollHeight ?? 0;
          const prevTop = el?.scrollTop ?? 0;

          this.entries = [...fetched, ...this.entries];
          this.hasMore = response.hasMore;

          this.$nextTick(() => {
            if (el) {
              el.scrollTop = el.scrollHeight - prevHeight + prevTop;
            }
          });
        }
      } catch (error) {
        if (requestId === this.requestId) {
          this.error = error?.message || this.$t("periscope.loadError");
        }
      } finally {
        if (requestId === this.requestId) {
          this.loading = false;
          this.loadingMore = false;
        }
      }
    },

    setupTimer(enabled) {
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null;
      }
      if (enabled) {
        this.timer = setInterval(() => this.load(true, true), 10000);
      }
    },

    async copyVisible() {
      if (this.filteredEntries.length === 0) {
        return;
      }
      try {
        const text = this.filteredEntries.map((entry) => entry.raw).join("\n\n");
        await navigator.clipboard.writeText(text);
        this.copiedVisible = true;
        clearTimeout(this.copiedVisibleTimer);
        this.copiedVisibleTimer = setTimeout(() => {
          this.copiedVisible = false;
        }, 1500);
      } catch (error) {
        this.error = this.$t("periscope.clipboardError");
      }
    },

    async copyEntry(entry) {
      try {
        await navigator.clipboard.writeText(entry.raw);
        this.copiedId = entry._id;
        clearTimeout(this.copiedTimer);
        this.copiedTimer = setTimeout(() => {
          if (this.copiedId === entry._id) {
            this.copiedId = null;
          }
        }, 1500);
      } catch (error) {
        this.error = this.$t("periscope.clipboardError");
      }
    },

    formatSize(bytes) {
      if (bytes === null || bytes === undefined) {
        return "";
      }
      const units = ["B", "KB", "MB", "GB"];
      let value = bytes;
      let unit = 0;
      while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
      }
      return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
    },

    formatDate(iso) {
      if (!iso) {
        return "";
      }
      return new Date(iso).toLocaleString(this.$panel.translation.code || "en");
    },
  },
};
</script>

<style>
.k-log-viewer-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.k-log-viewer-field {
  width: 16rem;
  max-width: 100%;
}

.k-log-viewer-field--narrow {
  width: 10rem;
}

.k-log-viewer-autorefresh {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: var(--color-gray-700);
  margin-left: auto;
}

.k-log-viewer-meta {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: var(--color-gray-700);
  margin-bottom: 0.5rem;
}

.k-log-viewer-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.k-log-viewer-levels {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
}

.k-log-viewer-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  height: 1.75rem;
  padding: 0 0.6rem;
  border: 0;
  border-radius: var(--rounded, 4px);
  outline: 1px solid var(--color-border, #ccc);
  background: var(--color-white, #fff);
  color: var(--color-text, #333);
  font-size: 0.75rem;
  cursor: pointer;
}

.k-log-viewer-pill[data-theme] {
  color: var(--theme-color-icon);
}

.k-log-viewer-pill:hover {
  background: var(--color-gray-100, #f0f0f0);
}

.k-log-viewer-pill--active {
  outline: 2px solid var(--theme-color-icon, var(--color-focus, #4a90d9));
  font-weight: 600;
}

.k-log-viewer-pill-count {
  color: var(--color-gray-500, #999);
  font-variant-numeric: tabular-nums;
}

.k-log-viewer-pill--active .k-log-viewer-pill-count {
  color: inherit;
}

.k-log-viewer-search {
  display: block;
  width: 100%;
  max-width: 16rem;
  height: var(--input-height, 2.25rem);
  padding: 0 0.75rem;
  margin-left: auto;
  background: var(--input-color-back, #fff);
  color: var(--input-color-text, #333);
  border: 0;
  border-radius: var(--input-rounded, var(--rounded, 4px));
  outline: 1px solid var(--input-color-border, var(--color-border, #ccc));
  box-shadow: var(--input-shadow, none);
  font-family: var(--input-font-family, var(--font-mono, monospace));
  font-size: var(--input-font-size, 0.8rem);
}

.k-log-viewer-search:focus {
  outline: 2px solid var(--color-focus, #4a90d9);
}

.k-log-viewer-content-wrapper {
  position: relative;
}

.k-log-viewer-content {
  max-height: 70vh;
  overflow: auto;
  background: var(--color-gray-900, #1a1a1a);
  border-radius: var(--rounded, 4px);
  font-family: var(--font-mono, monospace);
  font-size: 0.8rem;
  line-height: 1.4;
}

.k-log-viewer-jump {
  position: absolute;
  bottom: 0.75rem;
  right: 0.75rem;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.75rem;
  border: none;
  border-radius: var(--rounded, 4px);
  background: var(--color-focus, #4a90d9);
  color: #fff;
  font-size: 0.75rem;
  cursor: pointer;
  box-shadow: var(--shadow-md, 0 2px 6px rgba(0, 0, 0, 0.3));
}

.k-log-viewer-more {
  display: block;
  width: 100%;
  padding: 0.5rem;
  margin-bottom: 0.25rem;
  border: none;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  background: transparent;
  color: var(--color-gray-300, #ccc);
  cursor: pointer;
  font-family: inherit;
  font-size: inherit;
}

.k-log-viewer-more:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.05);
}

.k-log-viewer-more:disabled {
  cursor: default;
  opacity: 0.6;
}

.k-log-viewer-empty {
  padding: 1rem;
  color: var(--color-gray-500, #999);
}

.k-log-viewer-reset {
  margin-left: 0.5rem;
  border: none;
  background: transparent;
  color: var(--color-blue-300, #8ab8f0);
  cursor: pointer;
  text-decoration: underline;
  font-size: inherit;
  font-family: inherit;
}

.k-log-viewer-entry {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.35rem 2.25rem 0.35rem 0.75rem;
  border-left: 3px solid transparent;
}

.k-log-viewer-entry[data-theme] {
  border-left-color: var(--theme-color-icon);
}

.k-log-viewer-entry--critical {
  font-weight: 700;
}

.k-log-viewer-entry:hover {
  background: rgba(255, 255, 255, 0.04);
}

.k-log-viewer-level-icon {
  flex-shrink: 0;
  margin-top: 0.2rem;
  color: var(--theme-color-icon, var(--color-gray-500));
}

.k-log-viewer-entry-body {
  flex: 1;
  min-width: 0;
}

.k-log-viewer-entry-text {
  margin: 0;
  padding: 0;
  color: var(--color-gray-100, #eee);
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  font-size: inherit;
}

.k-log-viewer-ts {
  color: var(--color-gray-500, #888);
  margin-right: 0.5em;
}

.k-log-viewer-toggle {
  margin-top: 0.15rem;
  border: none;
  background: transparent;
  color: var(--color-gray-500, #999);
  cursor: pointer;
  text-decoration: underline;
  font-size: inherit;
  font-family: inherit;
  padding: 0;
}

.k-log-viewer-copy {
  position: absolute;
  top: 0.25rem;
  right: 0.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: none;
  border-radius: var(--rounded, 4px);
  background: rgba(255, 255, 255, 0.08);
  color: var(--color-gray-300, #ccc);
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.1s ease;
}

.k-log-viewer-entry:hover .k-log-viewer-copy {
  opacity: 1;
}

.k-log-viewer-copy:hover {
  background: rgba(255, 255, 255, 0.16);
}
</style>
