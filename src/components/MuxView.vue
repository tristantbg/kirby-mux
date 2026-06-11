<template>
  <k-panel-inside class="k-mux-view">
    <k-header>
      Mux Videos
      <template #buttons>
        <k-button
          icon="refresh"
          variant="filled"
          size="sm"
          :disabled="loading"
          @click="load"
        >
          {{ loading ? "Loading …" : "Refresh" }}
        </k-button>
      </template>
    </k-header>

    <k-stats
      :reports="reports"
      size="medium"
      class="k-mux-view__stats"
    />

    <k-table
      v-if="rows.length"
      :columns="columns"
      :rows="rows"
      :index="false"
      class="k-mux-view__table"
    >
      <template #header="{ column, columnIndex, label }">
        {{ label }}
      </template>

      <template #options="{ row }">
        <k-button-group>
          <k-button
            icon="refresh"
            size="xs"
            variant="filled"
            :disabled="refetching === row.id"
            title="Refetch Mux data from the Mux API"
            @click="refetch(row)"
          />
          <k-button
            icon="edit"
            :link="row.panelUrl"
            size="xs"
            variant="filled"
            title="Open file in Panel"
          />
          <k-button
            v-if="row.dashboardUrl"
            icon="open"
            :link="row.dashboardUrl"
            target="_blank"
            size="xs"
            variant="filled"
            title="Open asset in Mux dashboard"
          />
        </k-button-group>
      </template>
    </k-table>

    <k-empty
      v-else-if="!loading"
      icon="video"
      text="No Mux video files found."
    />
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    videos: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
  },
  data() {
    return {
      items: this.videos,
      summary: this.stats,
      loading: false,
      refetching: null,
    };
  },
  computed: {
    reports() {
      return [
        {
          label: "Total videos",
          value: String(this.summary.total ?? 0),
          icon: "video",
        },
        {
          label: "Ready",
          value: String(this.summary.ready ?? 0),
          icon: "check",
          theme: "positive",
        },
        {
          label: "Missing Mux data",
          value: String(this.summary.missing ?? 0),
          icon: "alert",
          theme: (this.summary.missing ?? 0) > 0 ? "negative" : "passive",
        },
      ];
    },
    columns() {
      return {
        thumbnail: { label: "", type: "html", width: "1/12" },
        filename: { label: "File", type: "html", width: "3/12" },
        parent: { label: "Page", type: "html", width: "2/12" },
        status: { label: "Status", type: "html", width: "2/12" },
        renditions: { label: "Renditions", type: "html", width: "2/12" },
        asset: { label: "Asset ID", type: "html", width: "2/12" },
      };
    },
    rows() {
      return this.items.map((video) => ({
        id: video.id,
        thumbnail: video.thumbnail
          ? `<img src="${video.thumbnail}" alt="" style="width:56px;height:32px;object-fit:cover;border-radius:var(--rounded-sm);" />`
          : "—",
        filename: `<span title="${this.escape(video.filename)}">${this.escape(
          video.filename
        )}</span>`,
        parent: this.escape(video.parentTitle || "—"),
        status: this.statusBadge(video),
        renditions: this.renditionsBadge(video),
        asset: this.assetCell(video),
        panelUrl: video.panelUrl,
        dashboardUrl: video.dashboardUrl,
      }));
    },
  },
  methods: {
    escape(value) {
      return String(value ?? "").replace(
        /[&<>"']/g,
        (c) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;",
          }[c])
      );
    },
    badge(label, color) {
      return `<span style="display:inline-block;padding:2px 8px;border-radius:var(--rounded);font-size:var(--text-xs);background:${color.bg};color:${color.fg};">${label}</span>`;
    },
    statusBadge(video) {
      const map = {
        ready: { bg: "var(--color-green-300)", fg: "var(--color-green-900)" },
        preparing: {
          bg: "var(--color-yellow-300)",
          fg: "var(--color-yellow-900)",
        },
        errored: { bg: "var(--color-red-300)", fg: "var(--color-red-900)" },
        missing: { bg: "var(--color-red-300)", fg: "var(--color-red-900)" },
      };
      const color = map[video.status] || {
        bg: "var(--color-gray-300)",
        fg: "var(--color-gray-900)",
      };
      return this.badge(video.status, color);
    },
    renditionsBadge(video) {
      if (!video.hasMuxData) return "—";
      const ready = video.renditionsStatus === "ready";
      const color = ready
        ? { bg: "var(--color-green-300)", fg: "var(--color-green-900)" }
        : { bg: "var(--color-gray-300)", fg: "var(--color-gray-900)" };
      return this.badge(video.renditionsStatus || "pending", color);
    },
    assetCell(video) {
      if (!video.assetId) return "—";
      const code = `<code style="font-size:var(--text-xs)">${this.escape(
        video.assetId
      )}</code>`;
      if (!video.dashboardUrl) return code;
      return `<a href="${this.escape(
        video.dashboardUrl
      )}" target="_blank" rel="noopener noreferrer" title="Open asset in Mux dashboard">${code}</a>`;
    },
    async load() {
      this.loading = true;
      try {
        const response = await this.$api.get("mux/videos");
        this.items = response.videos;
        this.summary = response.stats;
      } catch (error) {
        this.$panel.notification.error(error);
      } finally {
        this.loading = false;
      }
    },
    async refetch(row) {
      this.refetching = row.id;
      try {
        const updated = await this.$api.post("mux/refetch", { id: row.id });
        const index = this.items.findIndex((video) => video.id === row.id);
        if (index !== -1) {
          this.items.splice(index, 1, updated);
        }
        this.recomputeStats();
        this.$panel.notification.success("Mux data refetched");
      } catch (error) {
        this.$panel.notification.error(error);
      } finally {
        this.refetching = null;
      }
    },
    recomputeStats() {
      this.summary = {
        total: this.items.length,
        ready: this.items.filter((video) => video.status === "ready").length,
        missing: this.items.filter((video) => video.hasMuxData === false)
          .length,
      };
    },
  },
};
</script>

<style>
.k-mux-view__stats {
  margin-bottom: var(--spacing-6);
}
.k-mux-view__table code {
  word-break: break-all;
}
</style>
