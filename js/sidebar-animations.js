document.addEventListener("DOMContentLoaded", function () {
  const menuItems = document.querySelectorAll(".nav > li > a");

  menuItems.forEach((item) => {
    const icon = item.querySelector("lord-icon");
    if (!icon) return;

    item.addEventListener("mouseenter", (e) => {
      // Only act on user-initiated events.
      if (!e.isTrusted) return;

      icon.dispatchEvent(
        new MouseEvent("mouseenter", { bubbles: false, cancelable: true })
      );
    });

    item.addEventListener("mouseleave", (e) => {
      if (!e.isTrusted) return;

      icon.dispatchEvent(
        new MouseEvent("mouseleave", { bubbles: false, cancelable: true })
      );
    });
  });
});
