$(document).ready(function () {
    let currentUrl = window.location.pathname.split("/").pop(); // Get current page filename

    $(".nav a").each(function () {
        let link = $(this).attr("href").split("/").pop(); // Get href filename

        if (link === currentUrl) {
            $(".nav li").removeClass("active"); // Remove active from all
            $(this).parent().addClass("active"); // Add active to the correct menu item
        }
    });
});
