/**
 * Pauses the videos in a drupal-switch panel when another option is chosen,
 * so a hidden video does not keep playing. See drupal-pages.css.
 */
document.addEventListener('change', (event) => {
  const switcher = event.target.closest('.drupal-switch');
  if (switcher) {
    switcher.querySelectorAll('video').forEach((video) => video.pause());
  }
});
