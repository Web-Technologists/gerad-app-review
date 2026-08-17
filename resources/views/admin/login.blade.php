<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - UPI Management Center</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="h-full font-sans text-slate-100 antialiased flex items-center justify-center bg-gradient-to-tr from-slate-950 via-slate-900 to-indigo-950 p-4 relative overflow-hidden">
    <!-- Decorative Ambient Glows -->
    <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl -z-10 animate-pulse"></div>
    <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-emerald-500/5 rounded-full blur-3xl -z-10 animate-pulse"></div>

    <div class="w-full max-w-md">
        <!-- Logo and Heading -->
        <div class="text-center mb-8">
            <h1 class="font-outfit text-4xl font-extrabold tracking-tight bg-gradient-to-r from-indigo-400 via-sky-400 to-emerald-400 bg-clip-text text-transparent">
                UPI Manager
            </h1>
            <p class="mt-2 text-sm text-slate-400 font-medium">
                Centralized Control Panel for 27+ Shopify Stores
            </p>
        </div>

        <!-- Glassmorphism Login Card -->
        <div class="bg-slate-800/40 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-8 shadow-2xl shadow-slate-950/50">
            <h2 class="font-outfit text-2xl font-bold text-white mb-6 text-center">
                Administrator Authentication
            </h2>

            @if ($errors->any())
                <div class="mb-5 p-4 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (session('success'))
                <div class="mb-5 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-sm text-center">
                    {{ session('success') }}
                </div>
            @endif

            <form action="{{ route('admin.login.submit') }}" method="POST" class="space-y-6">
                @csrf
                
                <div>
                    <label for="password" class="block text-sm font-semibold text-slate-300 mb-2">
                        Admin Secret Key / Password
                    </label>
                    <input 
                        type="password" 
                        name="password" 
                        id="password" 
                        required
                        placeholder="••••••••" 
                        class="w-full px-4 py-3 bg-slate-900/60 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                    >
                </div>

                <div>
                    <button 
                        type="submit" 
                        class="w-full py-3 px-4 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-500 hover:to-indigo-600 active:from-indigo-700 active:to-indigo-800 text-white font-bold rounded-xl shadow-lg shadow-indigo-900/40 hover:shadow-indigo-500/20 transform hover:-translate-y-0.5 active:translate-y-0 transition duration-200"
                    >
                        Sign In to Panel
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-8 text-center text-xs text-slate-500">
            &copy; 2026 UPI Code Management System. All rights reserved.
        </div>
    </div>
</body>
</html>
