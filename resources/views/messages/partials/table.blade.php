{{-- table.blade.php --}}
<table class="table table-bordered" id="messagesTable">
    <thead>
        <tr>
            <th>ID</th>
            <th>From</th>
            <th>To</th>
            <th>Type</th>
            <th>Content</th>
            <th>Timestamp</th>
        </tr>
    </thead>

    <tbody>
        @foreach($allMessages as $msg)
            <tr>
                <td>{{ $msg->id }}</td>
                <td>{{ $msg->from }}</td>
                <td>{{ $msg->to }}</td>
                <td>{{ $msg->message_type }}</td>
                <td>{{ $msg->content }}</td>
                <td>{{ $msg->timestamp }}</td>
            </tr>
        @endforeach
    </tbody>
</table>