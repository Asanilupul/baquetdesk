<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $company->name }} · Menus</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">
    <header class="bg-slate-900 text-white">
        <div class="max-w-3xl mx-auto px-5 py-8 flex items-center gap-4">
            @if (! empty($company->logo))
                <img src="{{ $company->logo }}" alt="" class="h-14 w-14 rounded-2xl object-contain bg-white p-1">
            @endif
            <div>
                <h1 class="text-2xl font-black uppercase tracking-wide">{{ $company->name }}</h1>
                @if (! empty($company->subtitle))
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400 mt-1">{{ $company->subtitle }}</p>
                @endif
                <p class="text-xs font-bold uppercase tracking-widest text-indigo-300 mt-2">Our Menus</p>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-6 space-y-6">
        @if (count($menus) > 1)
            <nav class="flex flex-wrap gap-2">
                @foreach ($menus as $menu)
                    <a href="#menu-{{ $menu['id'] }}" class="px-4 py-2 rounded-full bg-white border border-slate-200 text-xs font-black uppercase text-indigo-700 shadow-sm">{{ $menu['name'] }}</a>
                @endforeach
            </nav>
        @endif

        @forelse ($menus as $menu)
            <section id="menu-{{ $menu['id'] }}" class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden scroll-mt-4">
                <div class="px-6 py-5 border-b border-slate-100">
                    <h2 class="text-xl font-black uppercase text-slate-900">{{ $menu['name'] }}</h2>
                    @if ($menu['description'] !== '')
                        <p class="text-sm text-slate-500 italic mt-1">{{ $menu['description'] }}</p>
                    @endif
                </div>

                @if ($menu['hall_prices'] !== [])
                    <div class="px-6 py-4 bg-indigo-50/60 border-b border-slate-100">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-indigo-500 mb-2">Price per person</h3>
                        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            @foreach ($menu['hall_prices'] as $price)
                                <li class="flex justify-between text-sm">
                                    <span class="font-bold uppercase text-slate-600">{{ $price['hall'] }}</span>
                                    <span class="font-black text-slate-900">{{ number_format($price['price'], 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="px-6 py-5 space-y-5">
                    @forelse ($menu['categories'] as $category)
                        <div>
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <h3 class="text-xs font-black uppercase tracking-widest text-indigo-600">{{ \App\Support\MenuCategoryOrder::normalize($category['name']) }}</h3>
                                @if ($category['choice_limit'] > 0)
                                    <span class="text-[10px] font-black uppercase px-3 py-1 rounded-full bg-amber-50 text-amber-700">Choose {{ $category['choice_limit'] }} of {{ count($category['items']) }}</span>
                                @endif
                            </div>
                            <ul class="space-y-1 pl-1">
                                @foreach ($category['items'] as $item)
                                    <li class="text-sm text-slate-700 flex gap-2"><span class="text-slate-300">•</span>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400 italic">No items in this menu yet.</p>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="bg-white rounded-3xl p-10 text-center text-slate-400 font-bold uppercase text-sm">No menus available yet.</div>
        @endforelse
    </main>

    <footer class="max-w-3xl mx-auto px-4 pb-10 text-center text-[11px] text-slate-400">
        @if (! empty($company->phone)){{ $company->phone }}@endif
        @if (! empty($company->phone) && ! empty($company->email)) · @endif
        @if (! empty($company->email)){{ $company->email }}@endif
    </footer>
</body>
</html>
