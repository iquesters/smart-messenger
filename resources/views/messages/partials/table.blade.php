<table id="messagesTable" class="table table-striped table-bordered">
    <thead>
        <tr>
            <th>Message ID</th>
            <th>From</th>
            <th>To</th>
            <th>Type</th>
            <th>Content</th>
            <th>Timestamp</th>
        </tr>
    </thead>
    <tbody>
    @foreach($messages as $msg)
        <tr>
            <td>{{ $msg->message_id }}</td>
            <td>{{ $msg->from }}</td>
            <td>{{ $msg->to }}</td>
            <td>{{ $msg->message_type }}</td>
            <td>{{ $msg->content }}</td>
            <td>{{ $msg->timestamp }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@push('scripts')
<script>
    function showView(view) {
        document.getElementById('tableView').classList.toggle('d-none', view !== 'table');
        document.getElementById('chatView').classList.toggle('d-none', view !== 'chat');
    }
    $(document).ready(function() {
        $('#messagesTable').DataTable({
            responsive: true
        });
    });
</script>
@endpush
