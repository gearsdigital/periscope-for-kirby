import LogViewerView from "./components/LogViewerView.vue";

panel.plugin("gearsdigital/periscope", {
  components: {
    "k-periscope-view": LogViewerView,
  },
});
