import * as pdfjsLib from "/DTS/js/plugins/build/pdf.mjs";
pdfjsLib.GlobalWorkerOptions.workerSrc = "/DTS/js/plugins/build/pdf.worker.mjs";

(function ($) {
  const requiresFile = $('input[name="fileOption"]:checked').val() === "with";

  let selectedFile = null;
  let latestUploadInfo = null;
  let _originalAvailableOfficesHTML = "";
  let fileRemoved = false; // Added: tracks if file removal is confirmed

  function previewFile(file) {
    const ext = file.name.split(".").pop().toLowerCase();
    const previewContainer = $("#filePreviewContainer");
    previewContainer.empty();
    if (ext === "pdf") {
      // Use FileReader to get blob URL
      const url = URL.createObjectURL(file);
      previewContainer.html(
        '<div id="fileupload-pdfjs-preview" style="width:100%; min-height:300px; max-height:60vh; overflow-y:auto; overflow-x: hidden; border:1px solid #ccd; padding: 10px;"></div>'
      );
      // Show loading indicator
      document.getElementById("fileupload-pdfjs-preview").innerHTML =
        '<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Loading PDF pages...</div>';
      renderPDFWithJS(url, "fileupload-pdfjs-preview");
    } else if (
      file.type ===
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ||
      ext === "docx"
    ) {
      const reader = new FileReader();
      reader.onload = (e) => {
        $("#filePreviewContainer").html(
          `<div class="preview-filename mb-2">
                <i class="fa fa-file-word-o text-primary"></i> ${file.name}
             </div>
             <div id="docx-text-preview"><em>Previewing DOCX, please wait...</em></div>`
        );
        if (window.mammoth) {
          mammoth.convertToHtml({ arrayBuffer: e.target.result }).then(
            (result) => $("#docx-text-preview").html(result.value),
            () =>
              $("#docx-text-preview").html(
                '<span class="text-danger">Could not preview .docx content.</span>'
              )
          );
        } else {
          $("#docx-text-preview").html(
            '<span class="text-warning">DOCX preview not available (mammoth.js missing).</span>'
          );
        }
      };
      reader.readAsArrayBuffer(file);
    } else if (file.type === "application/msword" || ext === "doc") {
      $("#filePreviewContainer").html(
        `<div class="preview-filename mb-2">
              <i class="fa fa-file-word-o text-secondary"></i> ${file.name}
           </div>
           <div>Preview not available for .doc file.
                <span class="text-muted">Download to view after upload.</span>
           </div>`
      );
    } else {
      $("#filePreviewContainer").html(
        `<div class="preview-filename mb-2">
              <i class="fa fa-file-o"></i> ${file.name}
           </div>
           <div>Preview not available for this file type.</div>`
      );
    }
  }

  function showFeedback(msg, isSuccess = true) {
    if (window.toastr) {
      if (isSuccess) toastr.success(msg);
      else toastr.error(msg);
    } else {
      const alertClass = isSuccess ? "alert-success" : "alert-danger";
      $("#user-feedback").html(`<div class="alert ${alertClass}">${msg}</div>`);
      if (isSuccess) {
        $("html, body").animate(
          {
            scrollTop: $("#user-feedback").offset().top - 100,
          },
          200
        );
      }
    }
  }

  function escapeText(str) {
    if (typeof str !== "string") return "";
    str = str.replace(/<\s*script.*?>.*?<\s*\/\s*script\s*>/gi, "");
    var temp = document.createElement("div");
    temp.textContent = str;
    var safe = temp.innerHTML.trim();
    if (safe.length > 3000) safe = safe.substring(0, 3000);
    return safe;
  }

  function escapeOffice(str) {
    return (str || "").replace(/[^\w\s\-\(\)\/\.,]+/g, "").trim();
  }

  // --- PDF.js multi-page preview (shared for drafts and uploads) ---
  async function renderPDFWithJS(source, containerId) {
    if (!window.pdfjsLib) {
      document.getElementById(containerId).innerHTML =
        '<span class="text-warning">PDF.js not loaded.</span>';
      return;
    }
    try {
      const loadingTask = pdfjsLib.getDocument(source);
      loadingTask.promise.then(
        function (pdf) {
          // Clear the container and prepare for multiple pages
          const container = document.getElementById(containerId);
          container.innerHTML = "";
          // Get container width for scaling
          const containerWidth = container.clientWidth || 600;
          // Function to render a single page
          function renderPage(pageNum) {
            return pdf
              .getPage(pageNum)
              .then(function (page) {
                // Calculate scale based on container width
                const unscaledViewport = page.getViewport({ scale: 1 });
                const scale = containerWidth / unscaledViewport.width;
                const viewport = page.getViewport({ scale });
                // Create canvas for this page
                const canvas = document.createElement("canvas");
                canvas.id = `${containerId}-page-${pageNum}`;
                canvas.style.display = "block";
                canvas.style.marginBottom = "10px";
                canvas.style.border = "1px solid #ddd";
                // Create page number label
                const pageLabel = document.createElement("div");
                pageLabel.textContent = `Page ${pageNum} of ${pdf.numPages}`;
                pageLabel.style.textAlign = "center";
                pageLabel.style.padding = "5px";
                pageLabel.style.backgroundColor = "#f8f9fa";
                pageLabel.style.fontSize = "12px";
                pageLabel.style.color = "#666";
                pageLabel.style.marginBottom = "5px";
                // Append to container
                container.appendChild(pageLabel);
                container.appendChild(canvas);
                const context = canvas.getContext("2d");
                if (viewport.width === 0 || viewport.height === 0) {
                  const errorMsg = document.createElement("div");
                  errorMsg.className = "text-danger";
                  errorMsg.textContent = `Page ${pageNum} has zero width/height.`;
                  container.appendChild(errorMsg);
                  return;
                }
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                const renderContext = {
                  canvasContext: context,
                  viewport: viewport,
                };
                return page
                  .render(renderContext)
                  .promise.catch(function (renderErr) {
                    const errorMsg = document.createElement("div");
                    errorMsg.className = "text-danger";
                    errorMsg.textContent = `Failed to render page ${pageNum}.`;
                    container.appendChild(errorMsg);
                  });
              })
              .catch(function (pageErr) {
                const errorMsg = document.createElement("div");
                errorMsg.className = "text-danger";
                errorMsg.textContent = `Failed to load page ${pageNum}.`;
                container.appendChild(errorMsg);
              });
          }
          // Render all pages sequentially
          let renderPromise = Promise.resolve();
          for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            renderPromise = renderPromise.then(() => renderPage(pageNum));
          }
          renderPromise.catch(function (err) {
            // Error rendering pages
          });
        },
        function (reason) {
          document.getElementById(containerId).innerHTML =
            '<span class="text-danger">Failed to load PDF preview.</span>';
        }
      );
    } catch (err) {
      document.getElementById(containerId).innerHTML =
        '<span class="text-danger">PDF.js error: ' + err.message + "</span>";
    }
  }

  function resetDocumentForm() {
    // Clear all inputs/selects (except draft_id)
    $("#docRouteForm")[0].reset();

    // Reset Summernote if exists
    if ($(".summernote").length) {
      $(".summernote").summernote("reset");
    }
    // Clear file preview and hidden file fields
    $("#filePreviewContainer").empty();
    $(
      "#uploaded_file, #uploaded_file_size, #file_path, #file_type, #file_size, #status"
    ).val("");

    // Restore dropzone text
    if ($("#dropText").length) {
      $("#dropText").text("Drag & drop a file here, or click to select");
    }

    // Restore available offices to original content (if applicable)
    if (_originalAvailableOfficesHTML !== "" && $("#availableOffices").length) {
      $("#availableOffices").html(_originalAvailableOfficesHTML);
    }
    // Clear recipient offices
    $("#recipientOffices").empty();

    // Reset JS memory of selected file
    selectedFile = null;
    latestUploadInfo = null;
    fileRemoved = false;
    $('input[name="draft_id"]').val("");
  }

  // Build the payload – use hidden fields if set, otherwise fall back to selectedFile properties.
  function getFormPayload(isDraft = false) {
    // Ensure the same max file size is used (10 MB here)
    const MAX_FILE_SIZE = 50 * 1024 * 1024;

    // If there's a file selected, check its size first
    if (selectedFile) {
      if (selectedFile.size > MAX_FILE_SIZE) {
        const errorMsg =
          "The selected file is too large. Maximum allowed size is 10 MB.";
        if (window.toastr) {
          toastr.error(errorMsg);
        } else {
          alert(errorMsg);
        }
        $("#fileInput").val(""); // Reset the file input
        selectedFile = null;
        return null; // Abort payload creation
      }
    }
    // Create a new FormData object
    let formData = new FormData();

    // Append standard text fields
    formData.append("draft_id", $('input[name="draft_id"]').val());
    formData.append("subject", escapeText($("#subject").val()));
    // Send raw HTML from Summernote for backend sanitization
    formData.append(
      "remarks",
      $(".summernote").length ? $(".summernote").summernote("code") : ""
    );

    // Append file only if selected
    if (selectedFile) {
      formData.append("uploaded_file", selectedFile, selectedFile.name);
      formData.append("file_size", selectedFile.size);
      formData.append("file_type", selectedFile.type);
    }
    // If a file was removed (and no new file selected), explicitly set file info to empty
    else if (fileRemoved) {
      formData.append("uploaded_file", "");
      formData.append("file_size", 0);
      formData.append("file_type", "");
      formData.append("file_path", "");
    }

    // Append multiple actions (assuming each checkbox has the same name "actions[]")
    $('input[name="actions[]"]:checked').each(function () {
      formData.append("actions[]", escapeText(this.value));
    });

    formData.append(
      "other_action",
      escapeText($('input[name="other_action"]').val())
    );

    // Append each recipient office (again using the 'offices[]' key)
    $("#recipientOffices li").each(function () {
      formData.append("offices[]", escapeOffice($(this).data("value")));
    });

    formData.append("urgency", $("#urgency").val() || "low");
    formData.append("action", isDraft ? "save_draft" : "finalize_route");

    return formData;
  }

  function validatePayload(isDraft, requiresFile = true) {
    const errors = [];
    const subject = escapeText($("#subject").val());
    if (!subject) errors.push("Subject is required.");

    if (!isDraft) {
      if (requiresFile) {
        // File is required only if 'withFile' is selected
        if (!selectedFile && !$("#file_path").val()) {
          errors.push("Please select a document file.");
        }
      }

      if (
        $('input[name="actions[]"]:checked').length === 0 &&
        !escapeText($('input[name="other_action"]').val())
      ) {
        errors.push(
          "Select at least one action request or provide a custom action."
        );
      }

      if ($("#recipientOffices li").length === 0) {
        errors.push("Select at least one recipient office.");
      }

      if (!$("#urgency").val()) {
        errors.push("Please select an urgency level.");
      }
    }

    return errors;
  }

  $(function () {
    // Cache original available offices
    if ($("#availableOffices").length) {
      _originalAvailableOfficesHTML = $("#availableOffices").html();
    }

    // PATCH: Handle file preview for existing drafts using web URL
    if ($("#file_path").length && $("#file_path").val() && !selectedFile) {
      const filePath = $("#file_path").val();
      // Use last segment for display, or uploaded_file value if available
      let displayName =
        $("#uploaded_file").val() || filePath.split(/[\\/]/).pop();
      const ext = displayName.split(".").pop().toLowerCase();
      let webUrl = filePath;
      if (displayName && filePath) {
        if (ext === "pdf") {
          const normalizedFilePath = getAbsoluteWebPath(webUrl);
          const pdfjsFullscreenUrl = `/DTS/js/plugins/web/viewer.html?file=${encodeURIComponent(
            normalizedFilePath
          )}&annotationEditorMode=2`;
          $("#filePreviewContainer").html(`
    <div class="preview-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
      <div class="preview-filename">
        <i class="fa fa-file-pdf-o text-danger"></i> ${displayName}
      </div>
      <button type="button" class="btn btn-link fullscreen-btn" title="Fullscreen" onclick="window.open('${pdfjsFullscreenUrl}', '_blank')">
        <i class="fa fa-expand"></i>
      </button>
    </div>
    <div id="pdfjs-canvas-preview" style="width:100%; min-height:300px; max-height:80vh; overflow:auto; border:1px solid #ccd; padding: 10px;"></div>
  `);
          renderPDFWithJS(normalizedFilePath, "pdfjs-canvas-preview");
        } else if (ext === "docx") {
          $("#filePreviewContainer").html(
            `<div class="preview-filename mb-2">
                  <i class="fa fa-file-word-o text-primary"></i> ${displayName}
               </div>
               <div id="docx-text-preview"><em>Previewing DOCX, please wait...</em></div>`
          );
          if (window.mammoth) {
            fetch(webUrl)
              .then((response) => response.blob())
              .then((blob) => {
                const reader = new FileReader();
                reader.onload = function (event) {
                  mammoth
                    .convertToHtml({ arrayBuffer: event.target.result })
                    .then((result) => {
                      document.getElementById("docx-preview").innerHTML =
                        result.value;
                    })
                    .catch(() => {
                      document.getElementById("docx-preview").innerHTML =
                        '<span class="text-danger">Could not preview .docx content.</span>';
                    });
                };
                reader.readAsArrayBuffer(blob);
              })
              .catch((error) => {
                console.error("Error fetching document:", error);
                document.getElementById("docx-text-preview").innerHTML =
                  '<span class="text-danger">Could not load document. Please try again.</span>';
              });
          } else {
            $("#docx-text-preview").html(
              '<span class="text-warning">DOCX preview not available (mammoth.js missing).</span>'
            );
          }
        } else if (ext === "doc") {
          $("#filePreviewContainer").html(
            `<div class="preview-filename mb-2">
                  <i class="fa fa-file-word-o text-secondary"></i> ${displayName}
               </div>
               <div>Preview not available for .doc file.
                    <span class="text-muted">Download to view after upload.</span>
               </div>`
          );
        } else {
          $("#filePreviewContainer").html(
            `<div class="preview-filename mb-2">
                  <i class="fa fa-file-o"></i> ${displayName}
               </div>
               <div>Preview not available for this file type.</div>`
          );
        }

        // Update dropzone text to show the file is already selected
        if ($("#dropText").length) {
          $("#dropText").text(displayName);
        }
      }
    }

    // PATCH: Preselect recipients in edit mode
    if (
      typeof preselectedRecipients !== "undefined" &&
      Array.isArray(preselectedRecipients) &&
      preselectedRecipients.length > 0
    ) {
      preselectedRecipients.forEach(function (office) {
        let $item = $("#availableOffices li").filter(function () {
          // Match by data-value attribute
          return $(this).attr("data-value") === office;
        });
        if ($item.length) {
          $item.appendTo("#recipientOffices");
        } else {
          // If the office isn't found in the available list, it might be a custom office
          // Create a new list item and append it to recipient offices
          const $newItem = $(
            `<li class="list-group-item" data-value="${escapeOffice(
              office
            )}">${office}</li>`
          );
          $("#recipientOffices").append($newItem);
        }
      });
    }

    // Sortable.js for office drag/drop
    if (window.Sortable) {
      const options = { group: "shared", animation: 150, sort: true };
      Sortable.create(document.getElementById("availableOffices"), options);
      Sortable.create(document.getElementById("recipientOffices"), options);
    }

    // Click-to-move between office lists
    $(document).on(
      "click",
      "#availableOffices li, #recipientOffices li",
      function () {
        const $item = $(this);
        const sourceId = $item.closest("ul").attr("id");
        const targetSelector =
          sourceId === "availableOffices"
            ? "#recipientOffices"
            : "#availableOffices";
        $item.slideUp(100, () => $item.appendTo(targetSelector).slideDown(100));
      }
    );

    // Filtering for office lists
    function setupFilter(inputSelector, listSelector) {
      $(inputSelector).on("input", function () {
        const term = $(this).val().toLowerCase();
        $(listSelector)
          .find("li")
          .each(function () {
            $(this).toggle($(this).text().toLowerCase().includes(term));
          });
      });
    }
    setupFilter("#searchAvailable", "#availableOffices");
    setupFilter("#searchRecipients", "#recipientOffices");

    // --- Manual dropzone logic:
    // Maximum file size: 10 MB
    // Maximum file size: 10 MB
    const MAX_FILE_SIZE = 50 * 1024 * 1024;

    // Grab references once
    const $fileInput = $("#fileInput");
    const $dropArea = $("#fileDropArea");
    const $dropText = $("#dropText");

    // Remove any existing handlers on these elements
    $fileInput.off("change");
    $dropArea.off("click dragenter dragover dragleave dragend drop");

    // Central file handler
    function handleFileSelection(file) {
      console.log(
        "[SizeCheck] file:",
        file && file.name,
        "size:",
        file && file.size
      );

      if (!file) {
        clearFileUI();
        return;
      }

      // Define max file size as 10 MB (change to 50 * 1024 * 1024 for 50 MB limit)
      const MAX_FILE_SIZE = 50 * 1024 * 1024;

      if (file.size > MAX_FILE_SIZE) {
        // Use toastr if available; otherwise, fallback to alert()
        const errorMsg = `"${file.name}" is ${(
          file.size /
          (1024 * 1024)
        ).toFixed(2)} MB — over the 10 MB limit.`;
        if (window.toastr) {
          toastr.error(errorMsg);
        } else {
          alert(errorMsg);
        }
        clearFileUI();
        return;
      }

      // If file size is within limit, continue processing
      selectedFile = file;
      $dropText.text(file.name);
      previewFile(file);
      latestUploadInfo = null;

      // Update the preview URL and file metadata
      const oldUrl = $("#file_path").val();
      if (oldUrl) URL.revokeObjectURL(oldUrl);
      const previewUrl = URL.createObjectURL(file);

      $("#uploaded_file").val(file.name);
      $("#uploaded_file_size").val(file.size);
      $("#file_type").val(file.type);
      $("#file_size").val(file.size);
      $("#file_path").val(previewUrl);
    }

    function clearFileUI() {
      selectedFile = null;
      $fileInput.val("");
      $dropText.text("Drag & drop a file here, or click to select");
      $(
        "#uploaded_file, #uploaded_file_size, #file_type, #file_size, #file_path"
      ).val("");
      $("#filePreviewContainer").empty(); // clear the preview immediately
      fileRemoved = true; // mark file as removed
    }
    // --- Confirmation for "without file" option ---
    // --- Confirmation for "without file" option ---
    $('input[name="fileOption"]').on("change", function () {
      const selectedOption = $(this).val();

      // Only run if switching to "without"
      if (selectedOption === "without") {
        if (selectedFile || $("#file_path").val()) {
          // Ask for confirmation
          if (
            !confirm(
              "Are you sure you want to remove the selected file? This action cannot be undone."
            )
          ) {
            // SOLUTION 1: Remove 'active' from all labels in the group
            // $(this).closest(".btn-group").find("label").removeClass("active");
            // SOLUTION 2: Remove 'active' from all labels globally (in case of DOM structure issues)
            // $("label.btn").removeClass("active");
            // SOLUTION 3: Uncheck all radios
            // $('input[name="fileOption"]').prop("checked", false);
            // SOLUTION 4: Check and activate only the 'with' radio
            var withRadio = $('input[name="fileOption"][value="with"]');
            withRadio.prop("checked", true);
            // withRadio.closest("label").addClass("active");
            // SOLUTION 5: Force Bootstrap's button group to update (trigger change)
            // withRadio.trigger("change");
            // SOLUTION 6: Remove focus from the radio to avoid sticky highlight
            // withRadio.blur();
            // SOLUTION 7: Use setTimeout to ensure DOM updates after event stack clears
            setTimeout(function () {
              withRadio.closest("label").addClass("active");
              $('input[name="fileOption"][value="without"]')
                .closest("label")
                .removeClass("active");
              var withoutRadio = $('input[name="fileOption"][value="without"]');
              withoutRadio.blur();
              withoutRadio.closest("label").blur();
            }, 10);
            return;
          }
          // If confirmed, clear the file
          clearFileUI();
        }
      }
    });

    // Re-attach new handlers:

    // 1) Clicking the drop area opens file dialog
    $dropArea.on("click", (e) => {
      if (e.target !== $fileInput[0]) {
        $fileInput.trigger("click");
      }
    });

    // 2) File dialog selection
    $fileInput.on("change", (e) => {
      handleFileSelection(e.target.files[0] || null);
    });

    // 3) Drag & drop
    $dropArea
      .on("dragenter dragover", (e) => {
        e.preventDefault();
        e.stopPropagation();
        $dropArea.addClass("dragover");
      })
      .on("dragleave dragend drop", (e) => {
        e.preventDefault();
        e.stopPropagation();
        $dropArea.removeClass("dragover");
      })
      .on("drop", (e) => {
        const dt = e.originalEvent.dataTransfer;
        if (dt && dt.files && dt.files.length) {
          handleFileSelection(dt.files[0]);
        }
      });

    // Now open your browser console (F12) and try dropping or selecting a 10.2 MB file —
    // you should see the “[SizeCheck] file:” log and get the alert, and no preview will appear.

    // Summernote initialization for remarks
    $(".summernote").summernote({
      height: 150,
      toolbar: [
        ["style", ["bold", "italic", "underline", "clear"]],
        ["fontsize", ["fontsize"]],
        ["color", ["color"]],
        ["para", ["ul", "ol", "paragraph"]],
        ["misc", ["undo", "redo"]],
        ["insert", ["link"]],
        ["view", ["fullscreen"]],
      ],
      callbacks: {
        onFullscreen: function (isFullscreen) {
          // toggle your sidebar’s visibility
          $(".sidebar-collapse").toggle(!isFullscreen);
        },
      },

      callbacks: {
        onImageUpload: function (files) {
          // Don't allow image uploads
          alert("Image/file upload is disabled in comments.");
        },
        onFileUpload: function (files) {
          alert("File upload is disabled in comments.");
        },
      },
    });
    // --- Button Handlers ---

    // Save as Draft
    $("#btnSaveDraft")
      .off("click")
      .on("click", function (e) {
        e.preventDefault();
        // Get which file option is selected.
        const requiresFile =
          $('input[name="fileOption"]:checked').val() === "with";

        const errors = validatePayload(false, requiresFile);
        if (errors.length) {
          return showFeedback(errors.join("<br>"), false);
        }
        const formData = getFormPayload(true);
        sendSaveDraft(formData);
      });

    function sendSaveDraft(formData) {
      $.ajax({
        url: "../server-logic/user-operations/document-management.php",
        type: "POST",
        data: formData,
        processData: false, // Important: do not process FormData
        contentType: false, // Important: let the browser set the multipart/form-data header
        success(res) {
          if (res.success) {
            if (window.toastr) toastr.success("Draft saved successfully!");
            else showFeedback("Draft saved successfully!", true);
            setTimeout(function () {
              resetDocumentForm();
            }, 300);
            window.location.href = "document_management.php";
          } else {
            if (window.toastr)
              toastr.error(res.message || "Failed to save draft.");
            else showFeedback(res.message || "Failed to save draft.", false);
          }
        },
        error() {
          if (window.toastr)
            toastr.error("An error occurred saving draft. Please try again.");
          else
            showFeedback(
              "An error occurred saving draft. Please try again.",
              false
            );
        },
      });
    }

    // Finalize & Route
    $("#btnFinalizeRoute")
      .off("click")
      .on("click", function (e) {
        e.preventDefault();

        // Get which file option is selected.
        const requiresFile =
          $('input[name="fileOption"]:checked').val() === "with";

        const errors = validatePayload(false, requiresFile);
        if (errors.length) {
          return showFeedback(errors.join("<br>"), false);
        }

        const formData = getFormPayload(false);
        sendSaveDoc(formData);
      });

    // Declare a global variable to store the file path:
    let globalFilePath = "";

    function sendSaveDoc(formData) {
      $.ajax({
        url: "../server-logic/user-operations/document-management.php",
        type: "POST",
        data: formData,
        processData: false, // Important for FormData
        contentType: false,
        success(res) {
          if (res.success) {
            if (res.draft_id) {
              $('input[name="draft_id"]').val(res.draft_id);
            }
            // Assume your server returns file_path in the response
            if (res.file_path) {
              globalFilePath = res.file_path;
              $("#file_path").val(res.file_path);
            }

            // Delay execution to try to catch the updated file_path
            setTimeout(() => {
              const reviewData = gatherReviewData();
              // If the hidden input is still empty, force it using globalFilePath:
              if (!reviewData.file_path && globalFilePath) {
                reviewData.file_path = globalFilePath;
              }
              console.log("Review data file path:", reviewData.file_path);
              populateReviewModal(reviewData);
              $("#reviewModal").modal("show");
            }, 100); // 2 seconds delay; tweak as needed

            $("#reviewModal")
              .off("hidden.bs.modal")
              .on("hidden.bs.modal", function () {
                resetDocumentForm();
              });
          } else {
            if (window.toastr)
              toastr.error(res.message || "Failed to save document.");
            else showFeedback(res.message || "Failed to save document.", false);
          }
        },
        error() {
          if (window.toastr)
            toastr.error(
              "An error occurred saving document. Please try again."
            );
          else
            showFeedback(
              "An error occurred saving document. Please try again.",
              false
            );
        },
      });
    }

    // Final Review Modal Confirm
    // $("#finalReviewForm").submit(function (e) {
    //   e.preventDefault();
    //   const draftId = $(this).find('input[name="draft_id"]').val();
    //   $.ajax({
    //     url: "../server-logic/user-operations/document-management.php",
    //     type: "POST",
    //     contentType: "application/json",
    //     dataType: "json",
    //     data: JSON.stringify({
    //       draft_id: draftId,
    //       action: "confirm_route",
    //     }),
    //     success: function (res) {
    //       if (res.success) {
    //         $("#reviewModal").modal("hide");
    //         if (window.toastr) toastr.success("Document routed successfully!");
    //         else showFeedback("Document routed successfully!", true);
    //         setTimeout(
    //           () => (window.location.href = "drafted_document.php"),
    //           1500
    //         );
    //       } else {
    //         $("#reviewModal").modal("hide");
    //         if (window.toastr)
    //           toastr.error(res.message || "Failed to route document.");
    //         else
    //           showFeedback(res.message || "Failed to route document.", false);
    //       }
    //     },
    //     error: function () {
    //       $("#reviewModal").modal("hide");
    //       if (window.toastr)
    //         toastr.error("An error occurred. Please try again.");
    //       else showFeedback("An error occurred. Please try again.", false);
    //     },
    //   });
    // });

    // Helper: Gather plain object for review modal from DOM
    function gatherReviewData() {
      return {
        draft_id: $('input[name="draft_id"]').val(),
        subject: escapeText($("#subject").val()),
        uploaded_file: selectedFile
          ? selectedFile.name
          : $("#uploaded_file").val() || "",
        file_path: $("#file_path").val(),
        remarks: $(".summernote").length
          ? $(".summernote").summernote("code")
          : "",
        actions: $('input[name="actions[]"]:checked')
          .map(function () {
            return escapeText(this.value);
          })
          .get(),
        other_action: escapeText($('input[name="other_action"]').val()),
        offices: $("#recipientOffices li")
          .map(function () {
            return escapeOffice($(this).data("value"));
          })
          .get(),
        urgency: $("#urgency").val() || "low",
      };
    }

    // Helper: Convert a file path or URL to an absolute web path for PDF.js viewer
    function getAbsoluteWebPath(path) {
      if (!path) return path;
      // Remove any ../ or ./ from the path
      path = path.replace(/\\/g, "/");
      // Remove everything before /DTS/documents/
      const match = path.match(/\/DTS\/documents\/[^/]+\.pdf/i);
      if (match) return match[0];
      const match2 = path.match(/DTS\/documents\/[^/]+\.pdf/i);
      if (match2) return "/" + match2[0];
      // Otherwise, fallback
      return "/DTS/documents/" + path.split("/").pop();
    }

    // Update the populateReviewModal function - replace the PDF rendering section
    function populateReviewModal(data) {
      const hasFile = !!data.uploaded_file;
      const isDocFile =
        data.uploaded_file &&
        data.uploaded_file.split(".").pop().toLowerCase() === "doc";
      const isDocxFile =
        data.uploaded_file &&
        data.uploaded_file.split(".").pop().toLowerCase() === "docx";

      // Normalize the file path to avoid CORS issues
      const normalizedFilePath = getAbsoluteWebPath(data.file_path);

      // Define the right column HTML for reuse
      function getRightColumnHtml(data) {
        return `
        <!-- Subject -->
        <div class="mb-3">
          <dt class="fw-bold text-primary">
            <i class="fa fa-clipboard me-2"></i>Subject
          </dt>
          <dd class="ps-sm-4">${
            data.subject || '<em class="text-muted">None</em>'
          }</dd>
        </div>
  
        <!-- Remarks -->
        <div class="mb-3">
          <dt class="fw-bold text-primary">
            <i class="fa fa-comments me-2"></i>Remarks
          </dt>
          <dd class="ps-sm-4">
            <div class="summernote-preview border rounded-3 p-2 bg-light">${
              data.remarks || '<em class="text-muted">None</em>'
            }</div>
          </dd>
        </div>
  
        <!-- Actions -->
        <div class="mb-3">
          <dt class="fw-bold text-primary">
            <i class="fa fa-tasks me-2"></i>Actions
          </dt>
          <dd class="ps-sm-4">
            ${(() => {
              let act = (data.actions || []).join(", ");
              if (data.other_action)
                act += act ? ", " + data.other_action : data.other_action;
              return act || '<em class="text-muted">None</em>';
            })()}
          </dd>
        </div>
  
        <!-- Urgency -->
        <div class="mb-3">
          <dt class="fw-bold text-danger">
            <i class="fa fa-exclamation-circle me-2"></i>Urgency
          </dt>
          <dd class="ps-sm-4 text-capitalize">${data.urgency || "low"}</dd>
        </div>
  
        <!-- Route Sequence -->
        <div>
          <div class="card border-0">
            <div class="card-header bg-transparent border-bottom pb-1">
              <h3 class="h6 mb-0 text-primary">
                <i class="fa fa-road me-2"></i>Route Sequence
              </h3>
            </div>
            <div class="card-body pt-2 pb-1 px-2">
              <ol class="list-group list-group-flush">
                ${(data.offices || [])
                  .map(
                    (office) => `
                  <li class="list-group-item bg-transparent px-0 py-1">
                    <span class="fs-6 text-dark">${office}</span>
                  </li>
                `
                  )
                  .join("")}
              </ol>
            </div>
          </div>
        </div>
      `;
      }

      let html = `
      <div class="row">
        ${
          hasFile || isDocxFile || isDocFile
            ? `
          <!-- Left: File Preview -->
          <div class="col-12 ${isDocFile ? "mb-5" : "col-lg-8 mb-5"}">
            ${(() => {
              if (data.uploaded_file) {
                const fileExt = data.uploaded_file
                  .split(".")
                  .pop()
                  .toLowerCase();
                if (fileExt === "pdf") {
                  return `
                    <label class="form-label fw-bold text-primary fs-6">
                      <i class="fa fa-file-text-o me-2"></i>File
                    </label>
                    <div id="review-pdfjs-preview" style="width:100%; min-height:300px; max-height:60vh; overflow-y:auto; overflow-x: hidden; border:1px solid #ccd; padding: 10px;"></div>
                  `;
                } else if (fileExt === "docx") {
                  setTimeout(() => {
                    fetch(normalizedFilePath)
                      .then((response) => response.arrayBuffer())
                      .then((arrayBuffer) =>
                        mammoth.convertToHtml({ arrayBuffer })
                      )
                      .then((result) =>
                        $("#docx-preview").find(".fs-5").html(result.value)
                      )
                      .catch((error) => {
                        console.error("DOCX preview error:", error);
                        $("#docx-preview")
                          .find(".fs-5")
                          .html(
                            '<span class="text-danger">Could not preview .docx content.</span>'
                          );
                      });
                  }, 500);
                  return `
                    <label class="form-label fw-bold text-primary fs-6"><i class="fa fa-file-alt me-2"></i>File</label>
                    <div id="docx-preview" class="border rounded-3 p-3 mb-2" style="min-height: 120px; max-height: 40vh; overflow-y: auto; overflow-x: hidden;">
                      <div class="fs-5">Loading preview...</div>
                    </div>
                    <a href="${normalizedFilePath}" target="_blank" class="btn btn-outline-primary btn-sm">
                      <i class="fa fa-download me-2"></i>Download DOCX
                    </a>`;
                } else if (fileExt === "doc") {
                  setTimeout(() => {
                    fetch(normalizedFilePath)
                      .then((response) => response.arrayBuffer())
                      .then((arrayBuffer) =>
                        mammoth.convertToHtml({ arrayBuffer })
                      )
                      .then((result) =>
                        $("#doc-preview").find(".fs-5").html(result.value)
                      )
                      .catch((error) => {
                        console.error("DOC preview error:", error);
                        $("#doc-preview")
                          .find(".fs-5")
                          .html(
                            '<span class="text-danger">Could not preview .doc content.</span>'
                          );
                      });
                  }, 500);
                  return `
                    <label class="form-label fw-bold text-primary fs-6"><i class="fa fa-file-alt me-2"></i>File</label>
                    <div id="doc-preview" class="border rounded-3 p-3 mb-2" style="min-height: 120px; max-height: 40vh; overflow-y: auto; overflow-x: hidden;">
                      <div class="fs-5">Loading preview...</div>
                    </div>
                    <a href="${normalizedFilePath}" target="_blank" class="btn btn-outline-primary btn-sm">
                      <i class="fa fa-download me-2"></i>Download DOC
                    </a>`;
                } else {
                  return `
                    <label class="form-label fw-bold text-primary fs-6"><i class="fa fa-file-alt me-2"></i>File</label>
                    <a href="${normalizedFilePath}" target="_blank" class="btn btn-outline-primary btn-sm">
                      <i class="fa fa-download me-2"></i>Download File
                    </a>`;
                }
              } else {
                return "";
              }
            })()}
          </div>
          <!-- Right: Subject, Remarks, etc. -->
          <div class="col-12 ${isDocFile ? "" : "col-lg-4 mb-4 mt-4"}">
            ${getRightColumnHtml(data)}
          </div>
        `
            : `
          <!-- Full-width: Subject, Remarks, etc. only -->
          <div class="col-12 mb-4">
            ${getRightColumnHtml(data)}
          </div>
        `
        }
      </div>
    `;

      $("#review-fields").html(html);
      // Render PDF in review modal if it's a PDF file (draft or FileReader)
      if (
        data.uploaded_file &&
        data.uploaded_file.split(".").pop().toLowerCase() === "pdf"
      ) {
        setTimeout(() => {
          const container = document.getElementById("review-pdfjs-preview");
          if (!container) return;
          // Show loading indicator
          container.innerHTML =
            '<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> Loading PDF pages...</div>';
          // Use FileReader blob URL if available, else normalizedFilePath
          let pdfSource = data.file_path;
          // If it's a blob URL (FileReader), use as is; else, normalize
          if (pdfSource && !pdfSource.startsWith("blob:")) {
            pdfSource = getAbsoluteWebPath(pdfSource);
          }
          renderPDFWithJS(pdfSource, "review-pdfjs-preview");
        }, 100);
      }

      $(".summernote-preview").html(
        data.remarks || '<em class="text-muted">None</em>'
      );
    }

    // Function removed as we're now using .html() directly

    // Block default form submit
    $("#docRouteForm").submit(function (e) {
      e.preventDefault();
    });
  });

  // --- PDF Fullscreen and Annotation Logic ---
  function setupPDFPreviewFullscreen(wrapperClassOrId, annotationToolsClass) {
    $(document)
      .off("click", `${wrapperClassOrId} .pdf-fullscreen-btn`)
      .on("click", `${wrapperClassOrId} .pdf-fullscreen-btn`, function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $wrapper = $(this).closest(".pdfjs-preview-wrapper");
        if (!$wrapper.length) return;
        // Detect if this is inside the review modal
        const isReviewModal = $wrapper.attr("id") === "review-pdfjs-preview";
        if (!$wrapper.hasClass("fullscreen")) {
          $wrapper.addClass("fullscreen");
          if (isReviewModal) {
            // Only expand to modal width/height
            $wrapper.css({
              position: "absolute",
              top: 0,
              left: 0,
              width: "100%",
              height: "80vh",
              background: "#fff",
              zIndex: 1051, // above modal backdrop
            });
          } else {
            // True fullscreen for filereader/draft
            $wrapper.css({
              position: "fixed",
              top: 0,
              left: 0,
              width: "100vw",
              height: "100vh",
              background: "#fff",
              zIndex: 9999,
            });
          }
          $wrapper.find(".pdf-annotation-tools").show();
          // Optionally, inject PDF.js annotation UI here
          if (
            window.PDFViewerApplication &&
            window.PDFViewerApplication.pdfViewer
          ) {
            // If using PDF.js default viewer, show annotation UI
          }
        } else {
          $wrapper.removeClass("fullscreen");
          $wrapper.removeAttr("style");
          $wrapper.find(".pdf-annotation-tools").hide();
        }
      });
    // Optionally, add ESC key to exit fullscreen
    $(document)
      .off("keydown.pdfFullscreen")
      .on("keydown.pdfFullscreen", function (e) {
        if (e.key === "Escape") {
          $(".pdfjs-preview-wrapper.fullscreen").each(function () {
            $(this).removeClass("fullscreen").removeAttr("style");
            $(this).find(".pdf-annotation-tools").hide();
          });
        }
      });
  }
  // Save as Draft
})(jQuery);
