/**
 * Settings › Catalogue: the "Edit" dialog of a game (title, price source ID, link and prices).
 * The game's values come from the button's data-game; the form is sent by bindAjaxForms() (settings.js).
 */
function catEditGame(btn) {
  const g = JSON.parse(btn.dataset.game);
  // Prices in the site's decimal format (the server accepts both . and ,)
  const price = v => v === null || v === '' ? '' : String(Number(v).toFixed(2)).replace('.', FMT.dec);
  document.getElementById('cg-id').value          = g.id;
  document.getElementById('cg-title').value       = g.title;
  document.getElementById('cg-external-id').value       = g.external_id;
  document.getElementById('cg-price-link').value     = g.price_link;
  document.getElementById('cg-loose_price').value = price(g.loose_price);
  document.getElementById('cg-cib_price').value   = price(g.cib_price);
  document.getElementById('cg-new_price').value   = price(g.new_price);
  document.getElementById('cat-game-dlg').classList.add('open');
  document.getElementById('cg-title').focus();
}

function catCloseGame() {
  document.getElementById('cat-game-dlg').classList.remove('open');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('cat-game-dlg')?.classList.contains('open')) catCloseGame();
});
