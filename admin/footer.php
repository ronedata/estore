  </main>
</div>
<footer class="text-center text-muted py-3" style="font-size:13px">
  &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> Admin
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Bootstrap JS (footer.php এ থাকলে ভালো) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  const btn = document.getElementById('btnSidebarToggle');
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  function closeSidebar(){
    sidebar?.classList.remove('show');
    backdrop?.classList.remove('show');
    document.body.style.overflow = '';
  }
  function openSidebar(){
    sidebar?.classList.add('show');
    backdrop?.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  btn?.addEventListener('click', ()=>{
    if (sidebar?.classList.contains('show')) closeSidebar();
    else openSidebar();
  });
  backdrop?.addEventListener('click', closeSidebar);

  // রিসাইজে ডেস্কটপ হলে অফক্যানভাস বন্ধ
  window.addEventListener('resize', ()=>{
    if (window.innerWidth >= 992) closeSidebar();
  });
})();
</script>

</body></html>
