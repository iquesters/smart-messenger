<div class="list-group">

    <h5 class="mb-3">Numbers</h5>

    @foreach($numbers as $num)
        <a href="?number={{ $num['number'] }}"
           class="list-group-item list-group-item-action 
           {{ $selectedNumber == $num['number'] ? 'active' : '' }}">
            {{ $num['number'] }}
        </a>
    @endforeach

</div>