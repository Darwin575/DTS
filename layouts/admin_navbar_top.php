<!-- navbar_top.php -->
<link rel="stylesheet" href="/DTS/css/notification-dropdown.css">
<nav class="navbar navbar-static-top" role="navigation" style="margin-bottom: 0">
  <div class="navbar-header">
    <a class="navbar-minimalize minimalize-styl-2 btn btn-primary" href="#">
      <i class="fa fa-bars"></i>
    </a>
    <form role="search" class="navbar-form-custom" action="search_results.html">
      <!-- Optional search inputs -->
    </form>
  </div>
  <ul class="nav navbar-top-links navbar-right">
    <li class="dropdown" id="notificationDropdown">
      <a class="dropdown-toggle count-info" data-toggle="dropdown" href="#">
        <i class="fa fa-bell"></i>
        <span class="label label-danger" id="notificationCount">0</span>
      </a>
      <ul class="dropdown-menu dropdown-alerts" id="notificationMenu">
        <li class="text-center">Loading notifications...</li>
      </ul>
    </li>
    <li>
      <a class="nav-link" href="../server-logic/config/logout.php">
        <i class="fa fa-sign-out"></i> Logout
      </a>
    </li>
  </ul>
</nav>

<script src="/DTS/js/jquery-3.1.1.min.js"></script>

<script>
  $(document).ready(function() {

    // Set up the Web Audio API context.
    var audioCtx = new(window.AudioContext || window.webkitAudioContext)();
    var notificationSoundBuffer;
    // Initialize previousCount from localStorage (if available) or default to 0.
    var previousCount = parseInt(localStorage.getItem('notifBadge'), 10) || 0;

    // Load the notification sound via fetch and decode it.
    function loadSound(url) {
      fetch(url)
        .then(function(response) {
          return response.arrayBuffer();
        })
        .then(function(arrayBuffer) {
          return audioCtx.decodeAudioData(arrayBuffer);
        })
        .then(function(decodedData) {
          notificationSoundBuffer = decodedData;
        })
        .catch(function(err) {
          console.error('Error loading sound:', err);
        });
    }

    // Replace the path below with the actual path to your sound file.
    loadSound('/DTS/audio/notification-alert-269289.mp3');

    // Function that plays the loaded notification sound.
    function playNotificationSound() {
      if (!notificationSoundBuffer) return; // Ensure the sound is loaded.
      var soundSource = audioCtx.createBufferSource();
      soundSource.buffer = notificationSoundBuffer;
      soundSource.connect(audioCtx.destination);
      soundSource.start(0);
    }

    // Function to fetch notifications and update the UI.
    function loadNotifications() {
      $.ajax({
        url: '../server-logic/admin-operations/admin-notification.php', // Adjust path as needed
        type: 'GET',
        dataType: 'json',
        cache: false,
        success: function(data) {
          // Convert the notification counts from strings to integers.
          var currentCount = parseInt(data.total_count, 10) || 0;
          var storedBadge = parseInt(localStorage.getItem('notifBadge'), 10) || 0;

          // If the server returns 0 but a stored badge exists, use that.
          if (currentCount === 0 && storedBadge !== 0) {
            currentCount = storedBadge;
          } else if (currentCount > 0) {
            // Update localStorage with the current count.
            localStorage.setItem('notifBadge', currentCount);
          }

          // Play notification sound only if there is an increase in count.
          if (currentCount > previousCount) {
            playNotificationSound();
          }
          previousCount = currentCount; // Update the stored notification count.

          // Update the badge displayed in the navbar.
          $("#notificationCount").text(currentCount);

          // Build the dropdown menu HTML.
          var menuHtml = "";
          if ($.trim(data.arrived_menu_html) !== "" && data.arrived_menu_html.charAt(0) !== "0") {
            menuHtml += "<li>" + data.arrived_menu_html + "</li>";
          }
          if ($.trim(data.rejected_menu_html) !== "" && data.rejected_menu_html.charAt(0) !== "0") {
            if ($.trim(menuHtml) !== "") {
              menuHtml += '<li class="dropdown-divider"></li>';
            }
            menuHtml += "<li>" + data.rejected_menu_html + "</li>";
          }
          if ($.trim(data.notification_menu_html) !== "" && data.notification_menu_html.charAt(0) !== "0") {
            if ($.trim(menuHtml) !== "") {
              menuHtml += '<li class="dropdown-divider"></li>';
            }
            menuHtml += "<li>" + data.notification_menu_html + "</li>";
          }
          menuHtml += '<li class="dropdown-divider"></li>' +
            '<li>' +
            '<div class="text-center link-block">' +
            '<a href="admin_notification.php" class="dropdown-item">' +
            '<strong>See All</strong>' +
            ' <i class="fa fa-angle-right"></i>' +
            '</a>' +
            '</div>' +
            '</li>';

          $("#notificationMenu").html(menuHtml);
        },
        error: function(xhr, status, error) {
          console.error("Error loading notifications: " + error);
        }
      });
    }

    // Refresh notifications every 5 seconds.
    setInterval(loadNotifications, 5000);

    // Refresh notifications when the bell is clicked.
    $("#notificationDropdown").on("click", function() {
      // Some browsers require a user gesture to resume the AudioContext.
      if (audioCtx.state === "suspended") {
        audioCtx.resume();
      }
      loadNotifications();
    });
  });
</script>