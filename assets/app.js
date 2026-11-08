function post(url,data){return fetch(url,{method:'POST',body:data}).then(r=>r.json())}
function addToCart(pid, qty=1){
  const fd = new FormData(); fd.append('product_id', pid); fd.append('qty', qty);
  post('cart_add.php', fd).then(r=>{ alert(r.message||'কার্টে যোগ হয়েছে'); refreshCartCount(); });
}
function refreshCartCount(){
  fetch('cart_count.php').then(r=>r.json()).then(r=>{
    const el = document.querySelector('#cartCount'); if(el) el.textContent = r.count;
  });
}
document.addEventListener('DOMContentLoaded', refreshCartCount);

// ---- Search Auto-suggest ----
(function(){
  const input  = document.getElementById('searchInput');
  const box    = document.getElementById('suggestBox');
  if(!input || !box) return;

  let t=null;
  input.addEventListener('input', e=>{
    const q = (input.value||'').trim();
    clearTimeout(t);
    if(q.length < 2){ box.style.display='none'; box.innerHTML=''; return; }
    t=setTimeout(async ()=>{
      try{
        const res = await fetch('search_suggest.php?q='+encodeURIComponent(q));
        const js  = await res.json();
        if(!Array.isArray(js) || js.length===0){ box.style.display='none'; box.innerHTML=''; return; }

        box.innerHTML = js.map(it=>`
          <div class="suggest-item" data-id="${it.id}">
            <img class="suggest-thumb" src="${it.image||'assets/placeholder.jpg'}" alt="">
            <div class="suggest-name">${it.name}</div>
            <div class="suggest-price">৳${Math.round(it.price)}</div>
          </div>
        `).join('');
        box.style.display='block';

        // click -> go to product details
        box.querySelectorAll('.suggest-item').forEach(el=>{
          el.addEventListener('click', ()=>{
            window.location.href = 'product.php?id=' + el.dataset.id;
          });
        });
      }catch(e){ /* ignore */ }
    }, 180); // debounce
  });

  // Hide on blur/click-outside
  document.addEventListener('click', (e)=>{
    if(!box.contains(e.target) && e.target!==input){
      box.style.display='none';
    }
  });
})();
