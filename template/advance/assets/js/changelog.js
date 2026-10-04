/**
 * Patch notes category filter: shows only one section (Classes, Professions, ...) and hides
 * updates with nothing left in them. The choice is kept in the URL hash (#filter=Classes).
 */
(function () {
  'use strict';

  var chips = Array.prototype.slice.call(document.querySelectorAll('.pn-chip'));
  var posts = Array.prototype.slice.call(document.querySelectorAll('.pn-post'));

  function apply(filter) {
    chips.forEach(function (chip) {
      var on = chip.getAttribute('data-filter') === filter;
      chip.classList.toggle('is-on', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    posts.forEach(function (post) {
      var any = false;
      post.querySelectorAll('[data-section]').forEach(function (block) {
        var show = !filter || block.getAttribute('data-section') === filter;
        block.hidden = !show;
        any = any || show;
      });
      // Group headings (New Features, Bug Fixes) only when something under them is showing.
      post.querySelectorAll('.pn-new, .pn-fixes').forEach(function (group) {
        group.hidden = !group.querySelector('[data-section]:not([hidden])');
      });
      post.hidden = !any;
    });
  }

  function fromHash() {
    var match = /^#filter=(.+)$/.exec(location.hash);
    return match ? decodeURIComponent(match[1]) : '';
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var filter = chip.getAttribute('data-filter');
      apply(filter);
      history.replaceState(null, '', filter ? '#filter=' + encodeURIComponent(filter) : location.pathname + location.search);
    });
  });

  if (fromHash()) {
    apply(fromHash());
  }
})();
