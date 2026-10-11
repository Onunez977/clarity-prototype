<link rel="icon" href="{{asset('img/cropped-Favicon-32x32.png')}}" sizes="32x32">
<link rel="icon" href="{{asset('/img/cropped-Favicon-192x192.png')}}" sizes="192x192">
<link rel="apple-touch-icon" href="{{asset('/img/cropped-Favicon-180x180.png')}}">
<meta name="msapplication-TileImage" content="{{config('app.url')}}/img/cropped-Favicon-270x270.png">

@if (config('app.env') !== 'local')
    <script type="text/javascript">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "q59afla9hl");
    </script>

    @auth
        <script>
            var beamer_config = {
                product_id : 'PZMpsAmf75197',
                button : false,
                user_id : "{{ auth()->user()->id }}",
                user_email : "{{ auth()->user()->email }}",
                user_name : "{{ auth()->user()->getFilamentName() }}",
            };
        </script>
        <script type="text/javascript" src="https://app.getbeamer.com/js/beamer-embed.js" defer="defer"></script>
    @endauth
@endif

@if (config('app.env') !== 'testing')
    <script defer src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google.maps.key') }}&loading=async&v=3.64"></script>
@endif
