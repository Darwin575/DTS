// /DTS/js/autoLogout.js

(function () {
  // Determine if we're on the login page (adjust this check if needed).
  var currentPath = window.location.pathname.toLowerCase();
  if (currentPath.indexOf("index") !== -1) {
    // Do nothing on the login page.
    return;
  }

  // Listen for changes via localStorage.
  window.addEventListener("storage", function (event) {
    if (event.key === "currentUser") {
      alert("Your session has changed because another user logged in.");
      window.location.href = "/DTS/index.php";
    }
  });

  // Use BroadcastChannel (if supported) for additional notifications.
  if (window.BroadcastChannel) {
    const sessionChannel = new BroadcastChannel("session_channel");
    sessionChannel.onmessage = function (event) {
      if (event.data && event.data.type === "NEW_LOGIN") {
        alert("A new session was detected. Redirecting to the login page.");
        window.location.href = "/DTS/index.php";
      }
    };
  }
})();
