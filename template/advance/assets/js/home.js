/**
 * The homepage's feature book: one chapter at a time behind tabs. Without this script every
 * chapter shows, one after another.
 */
(function () {
  var book = document.querySelector('.codex-book');
  if (!book) {
    return;
  }
  var tabs = Array.prototype.slice.call(book.querySelectorAll('[role="tab"]'));
  var pages = tabs.map(function (tab) {
    return document.getElementById(tab.getAttribute('aria-controls'));
  });

  function show(index, focus) {
    tabs.forEach(function (tab, i) {
      var on = i === index;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      pages[i].hidden = !on;
    });
    if (focus) {
      tabs[index].focus();
    }
  }

  tabs.forEach(function (tab, i) {
    tab.addEventListener('click', function () {
      show(i, false);
    });
    tab.addEventListener('keydown', function (event) {
      var next = {ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1}[event.key];
      if (next !== undefined) {
        event.preventDefault();
        show((next + tabs.length) % tabs.length, true);
      }
    });
  });

  book.classList.add('codex-js');
  show(0, false);
})();
