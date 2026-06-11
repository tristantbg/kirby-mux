<template>
  <figure class="k-default-file-preview k-mux-file-preview">
    <k-file-preview-frame>
      <VideoPlayer
        v-if="streamUrl"
        :src="streamUrl"
        :thumb="thumb"
      />
      <img
        v-else-if="thumbnail"
        :src="thumbnail"
        alt=""
      />
    </k-file-preview-frame>

    <k-file-preview-details :details="details" />
  </figure>
</template>

<script>
import VideoPlayer from "./VideoPlayer.vue";

export default {
  components: {
    VideoPlayer,
  },
  props: {
    details: Array,
    url: String,
    image: Object,
    thumbnail: String,
    streamUrl: String,
  },
  computed: {
    thumb() {
      if (!this.thumbnail) return {};
      return { src: this.thumbnail };
    },
  },
};
</script>

<style>
.k-mux-file-preview img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
