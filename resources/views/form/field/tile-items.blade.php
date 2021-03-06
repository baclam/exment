@foreach($datalist as $option)
<div class="col-xs-12 col-sm-6 tile-group-item">
    <div id="tile-{{$column}}-{{$loop->index}}" class="row tile" data-id="{{array_get($option, 'id')}}">
        @if(!is_null(array_get($option, 'thumbnail')))
        <div class="col-xs-3 tile-thumbnail">
            <img src="{{ array_get($option, 'thumbnail') }}" />
        </div>
        @endif
        <div class="{{ !is_null(array_get($option, 'thumbnail')) ? 'col-xs-9' : 'col-xs-12' }}">
            <p class="tile-title">{{ array_get($option, 'title') }}</p>
            @if(!is_null(array_get($option, 'description')))
            <p class="tile-description">{{ array_get($option, 'description') }}</p>
            @endif
            @if(!is_null(array_get($option, 'author')))
            <p class="tile-description">
                @if(!is_null(array_get($option, 'author_url')))
                    <a href="{{array_get($option, 'author_url')}}" target="_blank">
                        {{ array_get($option, 'author') }}
                    </a>
                @else
                    {{ array_get($option, 'author') }}
                @endif
            </p>
            @endif
        </div>
        <input type="hidden" class="tile-value" name="{{$name}}[]" />
    </div>
</div>
@endforeach

@if($paginator)
<div class="col-xs-12 col-sm-12 text-center">
{{ $paginator->links('exment::search.links') }}
</div>
@endif
