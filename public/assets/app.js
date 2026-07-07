(() => {
  const copyButtons = document.querySelectorAll("[data-copy]");
  copyButtons.forEach((button) => {
    button.addEventListener("click", async () => {
      const original = button.textContent;
      try {
        await navigator.clipboard.writeText(button.dataset.copy || window.location.href);
        button.textContent = "Copied";
      } catch (error) {
        button.textContent = "Copy failed";
      }
      window.setTimeout(() => {
        button.textContent = original;
      }, 1400);
    });
  });
})();
