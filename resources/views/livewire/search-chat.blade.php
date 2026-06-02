<div class="flex flex-col h-[calc(100vh-8rem)] relative">
    <!-- Header con botón de nueva consulta -->
    @if(!empty($messages))
        <div class="flex justify-end px-4 sm:px-6 pt-2 pb-1">
            <button 
                wire:click="newChat"
                class="flex items-center gap-1.5 text-xs font-medium text-zinc-500 hover:text-indigo-600 dark:text-zinc-400 dark:hover:text-indigo-400 transition-colors duration-200 px-3 py-1.5 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/20"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                </svg>
                Nueva consulta
            </button>
        </div>
    @endif

    <!-- Contenedor del Chat -->
    <div class="flex-1 overflow-y-auto px-4 sm:px-6 scroll-smooth" id="chat-container">
        <div class="max-w-3xl mx-auto space-y-8 pb-32 pt-4">
            
            @if(empty($messages))
                <div class="flex flex-col items-center justify-center h-full text-zinc-400 mt-20 fade-in">
                    <div class="w-16 h-16 bg-indigo-50 dark:bg-indigo-900/20 rounded-2xl flex items-center justify-center mb-6 shadow-sm border border-indigo-100 dark:border-indigo-800/30">
                        <flux:icon.magnifying-glass class="w-8 h-8 text-indigo-500" />
                    </div>
                    <h2 class="text-2xl font-semibold text-zinc-700 dark:text-zinc-200">¿Qué jurisprudencia buscas hoy?</h2>
                    <p class="text-base mt-3 text-center max-w-md text-zinc-500 dark:text-zinc-400">
                        Describe tu caso o situación legal. Analizaré la base de datos del TSJ y encontraré los criterios exactos aplicables.
                    </p>
                    
                    <div class="flex flex-wrap justify-center gap-2 mt-8 max-w-lg">
                        <button 
                            wire:click="$set('question', 'Un accionista quiere vender sus acciones pero los estatutos de la empresa lo prohíben. ¿Qué ha dicho la Sala sobre restricciones estatutarias a la cesión de acciones?')"
                            class="text-xs px-3.5 py-2 rounded-full border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-700 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-200"
                        >
                            📄 Restricción de venta de acciones
                        </button>
                        <button 
                            wire:click="$set('question', '¿Cuál es el criterio actual sobre la procedencia del amparo constitucional contra decisiones judiciales?')"
                            class="text-xs px-3.5 py-2 rounded-full border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-700 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-200"
                        >
                            ⚖️ Amparo contra sentencias
                        </button>
                        <button 
                            wire:click="$set('question', 'Trabajador despedido injustificadamente reclama prestaciones sociales e indemnización. ¿Qué criterios ha fijado la Sala?')"
                            class="text-xs px-3.5 py-2 rounded-full border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-700 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-200"
                        >
                            👷 Despido injustificado
                        </button>
                        <button 
                            wire:click="$set('question', 'Inquilino demandado por desalojo alega preferencia ofertiva. ¿Cómo ha resuelto la Sala estos casos?')"
                            class="text-xs px-3.5 py-2 rounded-full border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-indigo-300 hover:text-indigo-600 dark:hover:border-indigo-700 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-200"
                        >
                            🏠 Desalojo y preferencia ofertiva
                        </button>
                    </div>
                </div>
            @endif

            @foreach($messages as $index => $msg)
                @if($msg['role'] === 'user')
                    <!-- Mensaje del Usuario -->
                    <div class="flex justify-end">
                        <div class="bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 px-5 py-3 rounded-2xl rounded-tr-sm max-w-[85%] text-[15px] leading-relaxed shadow-sm border border-zinc-200/50 dark:border-zinc-700/50">
                            {{ $msg['content'] }}
                        </div>
                    </div>
                @else
                    <!-- Mensaje de la IA -->
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                <flux:icon.sparkles class="w-4 h-4" />
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="prose prose-zinc max-w-none dark:prose-invert prose-p:leading-relaxed prose-pre:bg-zinc-100 dark:prose-pre:bg-zinc-800 prose-pre:text-zinc-800 dark:prose-pre:text-zinc-200 prose-a:text-indigo-600 dark:prose-a:text-indigo-400 hover:prose-a:text-indigo-500 prose-headings:font-semibold prose-blockquote:border-l-indigo-500 prose-blockquote:bg-indigo-50/50 dark:prose-blockquote:bg-indigo-900/20 prose-blockquote:px-4 prose-blockquote:py-2 prose-blockquote:rounded-r-lg prose-blockquote:not-italic prose-blockquote:shadow-sm prose-blockquote:text-zinc-700 dark:prose-blockquote:text-zinc-300">
                                {!! Illuminate\Mail\Markdown::parse($msg['content']) !!}
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
            
            <!-- Indicador de Carga -->
            <div wire:loading wire:target="ask" class="flex gap-4 w-full">
                 <div class="flex-shrink-0 mt-1">
                    <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                        <flux:icon.sparkles class="w-4 h-4 animate-pulse" />
                    </div>
                </div>
                <div class="flex-1 pt-2.5">
                    <div class="flex space-x-2 items-center">
                        <div class="w-2 h-2 bg-zinc-400 dark:bg-zinc-500 rounded-full animate-bounce"></div>
                        <div class="w-2 h-2 bg-zinc-400 dark:bg-zinc-500 rounded-full animate-bounce" style="animation-delay: 0.15s"></div>
                        <div class="w-2 h-2 bg-zinc-400 dark:bg-zinc-500 rounded-full animate-bounce" style="animation-delay: 0.3s"></div>
                    </div>
                    <span class="text-xs text-zinc-400 mt-2 block">Buscando y analizando jurisprudencia...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Área de Input -->
    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-white via-white to-transparent dark:from-zinc-900 dark:via-zinc-900 pb-2 pt-10 px-4 pointer-events-none">
        <div class="max-w-3xl mx-auto relative pointer-events-auto">
            <div class="relative flex items-end bg-white dark:bg-zinc-800 rounded-2xl shadow-[0_4px_20px_-4px_rgba(0,0,0,0.1)] dark:shadow-[0_4px_20px_-4px_rgba(0,0,0,0.3)] border border-zinc-200 dark:border-zinc-700 overflow-hidden focus-within:ring-2 focus-within:ring-indigo-500/50 focus-within:border-indigo-500 transition-all duration-200 group">
                <textarea 
                    wire:model="question"
                    wire:keydown.enter.prevent="ask"
                    x-data="{ resize() { $el.style.height = '60px'; $el.style.height = Math.min($el.scrollHeight, 250) + 'px'; } }"
                    x-init="resize()"
                    @input="resize()"
                    class="w-full max-h-[250px] bg-transparent border-0 focus:ring-0 resize-none py-4 pl-4 pr-16 text-[15px] text-zinc-800 dark:text-zinc-200 placeholder-zinc-400 dark:placeholder-zinc-500 leading-relaxed"
                    style="height: 60px;"
                    placeholder="Describe el caso o situación legal..."
                    wire:loading.attr="disabled"
                ></textarea>
                
                <div class="absolute bottom-2 right-2">
                    <button 
                        wire:click="ask"
                        wire:loading.attr="disabled"
                        class="p-2.5 rounded-xl bg-zinc-100 hover:bg-indigo-600 text-zinc-500 hover:text-white dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-indigo-500 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center group-focus-within:bg-indigo-600 group-focus-within:text-white"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                            <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z" />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="text-center mt-3">
                <span class="text-[11px] text-zinc-400 dark:text-zinc-500 font-medium tracking-wide">Sententia AI puede cometer errores. Verifica siempre los criterios jurisprudenciales.</span>
            </div>
        </div>
    </div>
    
    <!-- Auto-scroll to bottom script -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            let scrolled = false;
            const container = document.getElementById('chat-container');
            
            Livewire.hook('morph.updating', () => {
                scrolled = container.scrollHeight - container.scrollTop === container.clientHeight;
            });

            Livewire.hook('morph.updated', () => {
                if(container) {
                    container.scrollTo({
                        top: container.scrollHeight,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>
    
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Custom scrollbar for webkit */
        #chat-container::-webkit-scrollbar {
            width: 6px;
        }
        #chat-container::-webkit-scrollbar-track {
            background: transparent; 
        }
        #chat-container::-webkit-scrollbar-thumb {
            background: rgba(156, 163, 175, 0.3); 
            border-radius: 10px;
        }
        #chat-container::-webkit-scrollbar-thumb:hover {
            background: rgba(156, 163, 175, 0.5); 
        }
    </style>
</div>