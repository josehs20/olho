<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Layout público (jornada avulsa e relatório por token). Difere do layout de
 * autenticação por ser largo — é uma página de conteúdo, não um formulário de
 * login.
 */
class PublicLayout extends Component
{
    public function render(): View
    {
        return view('layouts.public');
    }
}
