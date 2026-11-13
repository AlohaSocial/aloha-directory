// Aloha Directory: server filters (progressive enhancement; without JS every server is shown).
(function () {
  'use strict';

  var form = document.getElementById('server-filters');
  var list = document.getElementById('server-list');
  if (!form || !list) {
    return;
  }

  var cards = Array.prototype.slice.call(list.querySelectorAll('.server-card'));
  var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-filter]'));
  var count = document.getElementById('server-count');
  var noMatch = document.getElementById('no-match');
  var reset = document.getElementById('filter-reset');

  function matches(card, select) {
    var wanted = select.value;
    if (!wanted) {
      return true;
    }
    var value = card.getAttribute('data-' + select.getAttribute('data-filter')) || '';
    // data-languages holds a space-separated list; the other attributes hold one value.
    return value.split(' ').indexOf(wanted) !== -1;
  }

  function update() {
    var shown = 0;
    cards.forEach(function (card) {
      var visible = selects.every(function (select) { return matches(card, select); });
      card.hidden = !visible;
      if (visible) {
        shown++;
      }
    });
    if (count) {
      count.textContent = shown + (shown === 1 ? ' server' : ' servers');
    }
    if (noMatch) {
      noMatch.hidden = shown !== 0;
    }
    if (reset) {
      reset.disabled = selects.every(function (select) { return !select.value; });
    }
  }

  selects.forEach(function (select) {
    select.addEventListener('change', update);
  });
  if (reset) {
    reset.addEventListener('click', function () {
      selects.forEach(function (select) { select.value = ''; });
      update();
      if (selects[0]) {
        selects[0].focus();
      }
    });
  }
  form.addEventListener('submit', function (event) { event.preventDefault(); });

  form.hidden = false;
  update();
})();
