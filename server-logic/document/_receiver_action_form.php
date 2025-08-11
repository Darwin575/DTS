<form id="receiver-action-form" class="mt-3">
    <input type="hidden" name="document_id" value="<?= $document['document_id'] ?>">
    <div class="form-group">
        <label for="receiver_remark">Your Comment/Remark</label>
        <textarea id="receiver_remark" name="receiver_remark" class="form-control summernote"></textarea>
    </div>
    <div class="d-flex flex-column">
        <button type="button" class="btn btn-danger btn-block mb-2" style="font-weight:bold;" onclick="submitReceiverAction('reject')">
            <i class="fa fa-times"></i> Reject
        </button>
        <button type="button" class="btn btn-primary btn-block" style="font-weight:bold;" onclick="submitReceiverAction('approve')">
            <i class="fa fa-check"></i> Approve
        </button>
    </div>
</form>
<div id="receiver-action-result" class="mt-3"></div>
<script>
    $(function() {
        $('.summernote').summernote({
            height: 120,
            disableDragAndDrop: true,
            toolbar: [
                ['style', ['bold', 'italic', 'underline']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link']],
                ['view', ['fullscreen', 'codeview']]
            ],
            callbacks: {
                onImageUpload: function() {
                    return false;
                },
                onMediaDelete: function() {
                    return false;
                }
            }
        });
    });

    function submitReceiverAction(action) {
        const remark = $('#receiver_remark').val();
        const document_id = $('input[name="document_id"]').val();
        $.ajax({
            url: '../server-logic/user-operations/receive-action.php',
            type: 'POST',
            data: {
                document_id: document_id,
                action: action,
                remark: remark
            },
            success: function(response) {
                $('#receiver-action-result').html(
                    '<div class="alert alert-success">' + response.message + '</div>'
                );
            },
            error: function(xhr) {
                $('#receiver-action-result').html(
                    '<div class="alert alert-danger">Failed: ' + xhr.responseText + '</div>'
                );
            }
        });
    }
</script>