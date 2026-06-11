import VideoBlock from "./components/VideoBlock.vue";
import MuxView from "./components/MuxView.vue";

window.panel.plugin("robinscholz/kirby-mux", {
  blocks: {
    "mux-video": VideoBlock,
  },
  components: {
    "k-mux-view": MuxView,
  },
});
