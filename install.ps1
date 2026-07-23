<#
============================================================
 lua-server :: install (asistente grafico)
 Asistente con interfaz nativa (WPF), con pasos, para instalar
 Apache + PHP en esta carpeta eligiendo que opcionales quieres
 (mas versiones de PHP, MariaDB, Mailpit, HTTPS, phpMyAdmin).

   powershell -ExecutionPolicy Bypass -File .\install.ps1

 Para instalar todo sin interfaz (automatizacion, sin clics)
 usa en su lugar:
   .\bootstrap.ps1
============================================================
#>
$ErrorActionPreference = 'Stop'

# powershell.exe (Windows PowerShell 5.1) ya arranca en STA por defecto, pero
# si alguien lo lanza con pwsh (PowerShell 7, MTA por defecto) WPF no funciona.
if ([System.Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') {
    Start-Process powershell -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-STA', '-File', "`"$PSCommandPath`"")
    exit
}

Add-Type -AssemblyName PresentationFramework, PresentationCore, WindowsBase, System.Xaml

$Root = $PSScriptRoot
. (Join-Path $Root "config\install-lib.ps1")

# --- tema: igual mecanismo que el panel (oscuro por defecto, claro si Windows esta en claro) ---
function Get-WindowsLightTheme {
    try {
        $v = Get-ItemPropertyValue -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize' -Name 'AppsUseLightTheme' -ErrorAction Stop
        return [bool]$v
    } catch { return $true }
}
if (Get-WindowsLightTheme) {
    $bg = '#F4F6FB'; $card = '#FFFFFF'; $line = '#E3E7F0'; $tx = '#1A1D27'; $mut = '#5B6172'; $ac = '#2B6CFF'
} else {
    $bg = '#0F1117'; $card = '#1A1D27'; $line = '#2A2F3D'; $tx = '#E6E8EE'; $mut = '#8B90A0'; $ac = '#6EA8FE'
}
$brandStart = '#6EA8FE'; $brandEnd = '#9B6EFE'

$xamlText = @"
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation"
        xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
        Title="Instalar lua-server" Height="640" Width="820"
        WindowStartupLocation="CenterScreen" ResizeMode="NoResize"
        Background="$bg" FontFamily="Segoe UI">
  <Window.Resources>
    <LinearGradientBrush x:Key="BrandBrush" StartPoint="0,0" EndPoint="1,1">
      <GradientStop Color="$brandStart" Offset="0"/>
      <GradientStop Color="$brandEnd" Offset="1"/>
    </LinearGradientBrush>
    <SolidColorBrush x:Key="CardBrush" Color="$card"/>
    <SolidColorBrush x:Key="LineBrush" Color="$line"/>
    <SolidColorBrush x:Key="TextBrush" Color="$tx"/>
    <SolidColorBrush x:Key="MutedBrush" Color="$mut"/>
    <SolidColorBrush x:Key="AccentBrush" Color="$ac"/>

    <Style TargetType="TextBlock">
      <Setter Property="Foreground" Value="{StaticResource TextBrush}"/>
      <Setter Property="TextWrapping" Value="Wrap"/>
    </Style>
    <Style TargetType="CheckBox">
      <Setter Property="Foreground" Value="{StaticResource TextBrush}"/>
      <Setter Property="Margin" Value="0,5"/>
      <Setter Property="FontSize" Value="13"/>
    </Style>
    <Style x:Key="SectionTitle" TargetType="TextBlock">
      <Setter Property="FontWeight" Value="Bold"/>
      <Setter Property="FontSize" Value="13"/>
      <Setter Property="Margin" Value="0,0,0,8"/>
    </Style>
    <Style x:Key="Card" TargetType="Border">
      <Setter Property="Background" Value="{StaticResource CardBrush}"/>
      <Setter Property="BorderBrush" Value="{StaticResource LineBrush}"/>
      <Setter Property="BorderThickness" Value="1"/>
      <Setter Property="CornerRadius" Value="8"/>
      <Setter Property="Padding" Value="16"/>
      <Setter Property="Margin" Value="0,0,0,12"/>
    </Style>
    <ControlTemplate x:Key="RoundedButtonTemplate" TargetType="Button">
      <Border Background="{TemplateBinding Background}" BorderBrush="{TemplateBinding BorderBrush}" BorderThickness="{TemplateBinding BorderThickness}" CornerRadius="6">
        <ContentPresenter HorizontalAlignment="Center" VerticalAlignment="Center" Margin="18,10"/>
      </Border>
    </ControlTemplate>
    <Style x:Key="BtnPrimary" TargetType="Button">
      <Setter Property="Template" Value="{StaticResource RoundedButtonTemplate}"/>
      <Setter Property="Background" Value="{StaticResource BrandBrush}"/>
      <Setter Property="Foreground" Value="White"/>
      <Setter Property="BorderThickness" Value="0"/>
      <Setter Property="FontWeight" Value="Bold"/>
      <Setter Property="FontSize" Value="13"/>
      <Setter Property="Cursor" Value="Hand"/>
      <Setter Property="Margin" Value="8,0,0,0"/>
      <Style.Triggers>
        <Trigger Property="IsMouseOver" Value="True"><Setter Property="Opacity" Value="0.88"/></Trigger>
        <Trigger Property="IsEnabled" Value="False"><Setter Property="Opacity" Value="0.5"/></Trigger>
      </Style.Triggers>
    </Style>
    <Style x:Key="BtnGhost" TargetType="Button">
      <Setter Property="Template" Value="{StaticResource RoundedButtonTemplate}"/>
      <Setter Property="Background" Value="{StaticResource CardBrush}"/>
      <Setter Property="Foreground" Value="{StaticResource TextBrush}"/>
      <Setter Property="BorderBrush" Value="{StaticResource LineBrush}"/>
      <Setter Property="BorderThickness" Value="1"/>
      <Setter Property="FontSize" Value="13"/>
      <Setter Property="Cursor" Value="Hand"/>
      <Setter Property="Margin" Value="8,0,0,0"/>
      <Style.Triggers>
        <Trigger Property="IsMouseOver" Value="True"><Setter Property="BorderBrush" Value="{StaticResource AccentBrush}"/></Trigger>
        <Trigger Property="IsEnabled" Value="False"><Setter Property="Opacity" Value="0.5"/></Trigger>
      </Style.Triggers>
    </Style>
  </Window.Resources>

  <Grid>
    <Grid.RowDefinitions>
      <RowDefinition Height="Auto"/>
      <RowDefinition Height="Auto"/>
      <RowDefinition Height="*"/>
      <RowDefinition Height="Auto"/>
    </Grid.RowDefinitions>

    <StackPanel Grid.Row="0" Orientation="Horizontal" Margin="28,22,28,10" VerticalAlignment="Center">
      <Viewbox Width="40" Height="38" Margin="0,0,14,0">
        <Canvas Width="64" Height="60">
          <Path Data="M 17,5 V 31 H 29" Stroke="{StaticResource BrandBrush}" StrokeThickness="6" StrokeStartLineCap="Round" StrokeEndLineCap="Round" StrokeLineJoin="Round"/>
          <Path Data="M 35,5 V 24 Q 35,32 43,32 Q 51,32 51,24 V 5" Stroke="{StaticResource BrandBrush}" StrokeThickness="6" StrokeStartLineCap="Round" StrokeEndLineCap="Round" StrokeLineJoin="Round"/>
          <Path Data="M 22,54 L 32,22 L 42,54" Stroke="{StaticResource BrandBrush}" StrokeThickness="6" StrokeStartLineCap="Round" StrokeEndLineCap="Round" StrokeLineJoin="Round"/>
          <Ellipse Canvas.Left="30" Canvas.Top="43" Width="4" Height="4" Fill="{StaticResource BrandBrush}"/>
        </Canvas>
      </Viewbox>
      <StackPanel>
        <TextBlock Text="lua-server" FontSize="20" FontWeight="Bold"/>
        <TextBlock Text="Asistente de instalacion" Foreground="{StaticResource MutedBrush}" FontSize="12"/>
      </StackPanel>
    </StackPanel>

    <StackPanel Grid.Row="1" Margin="28,0,28,14">
      <TextBlock x:Name="TxtStepLabel" Text="Paso 1 de 4: Bienvenida" FontSize="12" Foreground="{StaticResource MutedBrush}" Margin="0,0,0,6"/>
      <StackPanel Orientation="Horizontal">
        <Border x:Name="Seg1" Width="170" Height="4" CornerRadius="2" Background="{StaticResource BrandBrush}" Margin="0,0,4,0"/>
        <Border x:Name="Seg2" Width="170" Height="4" CornerRadius="2" Background="{StaticResource LineBrush}" Margin="0,0,4,0"/>
        <Border x:Name="Seg3" Width="170" Height="4" CornerRadius="2" Background="{StaticResource LineBrush}" Margin="0,0,4,0"/>
        <Border x:Name="Seg4" Width="170" Height="4" CornerRadius="2" Background="{StaticResource LineBrush}"/>
      </StackPanel>
    </StackPanel>

    <Grid Grid.Row="2" Margin="28,0,28,10">
      <StackPanel x:Name="PanelWelcome">
        <TextBlock Text="Bienvenido" FontSize="17" FontWeight="Bold" Margin="0,0,0,8"/>
        <TextBlock Margin="0,0,0,14">Este asistente instala Apache, PHP y las herramientas que elijas directamente en esta carpeta. Todo se descarga desde aqui: no hace falta nada mas instalado en el sistema (salvo Visual C++ Redistributable, si tu Windows no lo tiene ya).</TextBlock>
        <Border Style="{StaticResource Card}">
          <StackPanel>
            <TextBlock Text="Se instala siempre" Style="{StaticResource SectionTitle}"/>
            <TextBlock Text="- Apache 2.4 + mod_fcgid"/>
            <TextBlock Text="- PHP 8.4 (version recomendada, LTS)"/>
            <TextBlock Text="- Composer"/>
            <TextBlock Text="- Visual C++ Redistributable (se descarga; se instala aparte si hace falta)"/>
            <TextBlock Text="- Panel de administracion web"/>
          </StackPanel>
        </Border>
        <TextBlock x:Name="TxtRootPath" Foreground="{StaticResource MutedBrush}" FontSize="12" Margin="0,4,0,0"/>
      </StackPanel>

      <ScrollViewer x:Name="PanelComponents" Visibility="Collapsed" VerticalScrollBarVisibility="Auto">
        <StackPanel>
          <TextBlock Text="Elige que mas instalar" FontSize="17" FontWeight="Bold" Margin="0,0,0,4"/>
          <TextBlock Foreground="{StaticResource MutedBrush}" FontSize="12" Margin="0,0,0,14">Todo esto es opcional: puedes anadirlo mas adelante volviendo a ejecutar este asistente.</TextBlock>

          <Border Style="{StaticResource Card}">
            <StackPanel>
              <TextBlock Text="Versiones de PHP adicionales" Style="{StaticResource SectionTitle}"/>
              <CheckBox x:Name="CbPhp85" Content="PHP 8.5"/>
              <CheckBox x:Name="CbPhp83" Content="PHP 8.3"/>
              <CheckBox x:Name="CbPhp82" Content="PHP 8.2"/>
              <CheckBox x:Name="CbPhp81" Content="PHP 8.1"/>
              <CheckBox x:Name="CbPhp74" Content="PHP 7.4 (proyectos legacy)"/>
              <CheckBox x:Name="CbPhp71" Content="PHP 7.1 (proyectos legacy, p.ej. Laravel 5.x)"/>
            </StackPanel>
          </Border>

          <Border Style="{StaticResource Card}">
            <StackPanel>
              <TextBlock Text="Base de datos" Style="{StaticResource SectionTitle}"/>
              <CheckBox x:Name="CbMariaDB" Content="MariaDB 11.8 (MySQL nativo, root sin contrasena en 127.0.0.1:3306)"/>
              <CheckBox x:Name="CbPostgres" Content="PostgreSQL 16 (nativo, postgres sin contrasena en 127.0.0.1:5432)"/>
            </StackPanel>
          </Border>

          <Border Style="{StaticResource Card}">
            <StackPanel>
              <TextBlock Text="Correo de pruebas" Style="{StaticResource SectionTitle}"/>
              <CheckBox x:Name="CbMailpit" Content="Mailpit (captura los emails que envian tus proyectos)"/>
            </StackPanel>
          </Border>

          <Border Style="{StaticResource Card}">
            <StackPanel>
              <TextBlock Text="HTTPS y administracion" Style="{StaticResource SectionTitle}"/>
              <CheckBox x:Name="CbMkcert" Content="mkcert (certificados HTTPS locales de confianza)"/>
              <CheckBox x:Name="CbPma" Content="phpMyAdmin (requiere MariaDB; tema por defecto)" IsEnabled="False"/>
            </StackPanel>
          </Border>
        </StackPanel>
      </ScrollViewer>

      <StackPanel x:Name="PanelProgress" Visibility="Collapsed">
        <TextBlock Text="Instalando" FontSize="17" FontWeight="Bold" Margin="0,0,0,8"/>
        <TextBlock x:Name="TxtStatus" Text="Preparando..." Margin="0,0,0,10"/>
        <ProgressBar x:Name="PbProgress" Height="18" Minimum="0" Maximum="100" Margin="0,0,0,14"/>
        <Border Style="{StaticResource Card}" Padding="0">
          <ListBox x:Name="LstLog" Height="300" Background="{StaticResource CardBrush}" Foreground="{StaticResource MutedBrush}" BorderThickness="0" FontFamily="Consolas" FontSize="12"/>
        </Border>
      </StackPanel>

      <StackPanel x:Name="PanelDone" Visibility="Collapsed">
        <TextBlock Text="Todo listo" FontSize="17" FontWeight="Bold" Margin="0,0,0,8"/>
        <TextBlock x:Name="TxtDoneSummary" Margin="0,0,0,18"/>
        <StackPanel Orientation="Horizontal">
          <Button x:Name="BtnLaunch" Content="Iniciar servidor y abrir panel" Style="{StaticResource BtnPrimary}" Margin="0,0,8,0"/>
          <Button x:Name="BtnVcRedist" Content="Instalar Visual C++ Redistributable" Style="{StaticResource BtnGhost}"/>
        </StackPanel>
      </StackPanel>
    </Grid>

    <StackPanel x:Name="Footer" Grid.Row="3" Orientation="Horizontal" HorizontalAlignment="Right" Margin="28,10,28,22">
      <Button x:Name="BtnBack" Content="Atras" Style="{StaticResource BtnGhost}" Visibility="Collapsed"/>
      <Button x:Name="BtnNext" Content="Empezar" Style="{StaticResource BtnPrimary}"/>
      <Button x:Name="BtnCancel" Content="Cancelar" Style="{StaticResource BtnGhost}"/>
    </StackPanel>
  </Grid>
</Window>
"@

$reader = [System.Xml.XmlNodeReader]::new([xml]$xamlText)
$window = [System.Windows.Markup.XamlReader]::Load($reader)

$panelWelcome    = $window.FindName('PanelWelcome')
$panelComponents = $window.FindName('PanelComponents')
$panelProgress   = $window.FindName('PanelProgress')
$panelDone       = $window.FindName('PanelDone')
$txtStepLabel    = $window.FindName('TxtStepLabel')
$seg1 = $window.FindName('Seg1'); $seg2 = $window.FindName('Seg2'); $seg3 = $window.FindName('Seg3'); $seg4 = $window.FindName('Seg4')
$txtRootPath     = $window.FindName('TxtRootPath')
$cbPhp85 = $window.FindName('CbPhp85'); $cbPhp83 = $window.FindName('CbPhp83'); $cbPhp82 = $window.FindName('CbPhp82')
$cbPhp81 = $window.FindName('CbPhp81'); $cbPhp74 = $window.FindName('CbPhp74'); $cbPhp71 = $window.FindName('CbPhp71')
$cbMariaDB = $window.FindName('CbMariaDB'); $cbMailpit = $window.FindName('CbMailpit')
$cbPostgres = $window.FindName('CbPostgres')
$cbMkcert = $window.FindName('CbMkcert'); $cbPma = $window.FindName('CbPma')
$txtStatus = $window.FindName('TxtStatus'); $pbProgress = $window.FindName('PbProgress'); $lstLog = $window.FindName('LstLog')
$txtDoneSummary = $window.FindName('TxtDoneSummary')
$btnLaunch = $window.FindName('BtnLaunch'); $btnVcRedist = $window.FindName('BtnVcRedist')
$footer = $window.FindName('Footer'); $btnBack = $window.FindName('BtnBack'); $btnNext = $window.FindName('BtnNext'); $btnCancel = $window.FindName('BtnCancel')

$brandBrush = $window.Resources['BrandBrush']
$lineBrush  = $window.Resources['LineBrush']

$txtRootPath.Text = "Se instalara en: $Root"

$cbMariaDB.Add_Checked({ $cbPma.IsEnabled = $true })
$cbMariaDB.Add_Unchecked({ $cbPma.IsEnabled = $false; $cbPma.IsChecked = $false })

$script:step = 'welcome'
$script:stepNumbers = @{ welcome = 1; components = 2; progress = 3; done = 4 }
$script:stepLabels  = @{ welcome = 'Bienvenida'; components = 'Componentes'; progress = 'Instalando'; done = 'Listo' }

function Update-StepBar {
    param([int]$N)
    $segs = @($seg1, $seg2, $seg3, $seg4)
    for ($i = 0; $i -lt 4; $i++) {
        $segs[$i].Background = if (($i + 1) -le $N) { $brandBrush } else { $lineBrush }
    }
}

function Show-Step {
    param([string]$Name)
    $script:step = $Name
    $panelWelcome.Visibility    = if ($Name -eq 'welcome')    { 'Visible' } else { 'Collapsed' }
    $panelComponents.Visibility = if ($Name -eq 'components') { 'Visible' } else { 'Collapsed' }
    $panelProgress.Visibility   = if ($Name -eq 'progress')   { 'Visible' } else { 'Collapsed' }
    $panelDone.Visibility       = if ($Name -eq 'done')       { 'Visible' } else { 'Collapsed' }
    Update-StepBar $script:stepNumbers[$Name]
    $txtStepLabel.Text = "Paso $($script:stepNumbers[$Name]) de 4: $($script:stepLabels[$Name])"

    switch ($Name) {
        'welcome'    { $footer.Visibility = 'Visible'; $btnBack.Visibility = 'Collapsed'; $btnNext.Content = 'Empezar'; $btnNext.Visibility = 'Visible'; $btnNext.IsEnabled = $true; $btnCancel.Content = 'Cancelar' }
        'components' { $footer.Visibility = 'Visible'; $btnBack.Visibility = 'Visible';   $btnNext.Content = 'Instalar'; $btnNext.Visibility = 'Visible'; $btnNext.IsEnabled = $true; $btnCancel.Content = 'Cancelar' }
        'progress'   { $footer.Visibility = 'Visible'; $btnBack.Visibility = 'Collapsed'; $btnNext.Visibility = 'Collapsed'; $btnCancel.Content = 'Cancelar instalacion' }
        'done'       { $footer.Visibility = 'Collapsed' }
    }
}

function Start-Install {
    $checkMap = @{
        php85 = $cbPhp85; php83 = $cbPhp83; php82 = $cbPhp82; php81 = $cbPhp81; php74 = $cbPhp74; php71 = $cbPhp71
        mariadb = $cbMariaDB; postgres = $cbPostgres; mailpit = $cbMailpit; mkcert = $cbMkcert; phpmyadmin = $cbPma
    }
    $selected = @()
    foreach ($k in $checkMap.Keys) { if ($checkMap[$k].IsChecked -eq $true) { $selected += $k } }

    $allItems = Sort-CatalogItems (Get-InstallCatalog)
    $script:installItems = @($allItems | Where-Object { $_.Required -or ($selected -contains $_.Id) })

    $script:syncHash = [hashtable]::Synchronized(@{
        Log     = [System.Collections.ArrayList]::Synchronized((New-Object System.Collections.ArrayList))
        Percent = 0
        Status  = 'Preparando...'
        Done    = $false
        Error   = $null
    })

    $installScript = {
        param($Root, $Items, $Sync)
        try {
            . (Join-Path $Root "config\install-lib.ps1")
            $dl = Join-Path $Root "downloads"
            New-Item -ItemType Directory -Force -Path $dl | Out-Null
            $total = ($Items.Count * 2) + 1
            $done = 0
            foreach ($it in $Items) {
                $Sync.Status = "Descargando $($it.Label)..."
                Invoke-CatalogDownload -Item $it -DownloadsDir $dl | Out-Null
                $done++
                $Sync.Percent = [int](($done / $total) * 100)
                [void]$Sync.Log.Add("Descargado: $($it.Label)")
            }
            foreach ($it in $Items) {
                $Sync.Status = "Instalando $($it.Label)..."
                Install-CatalogItem -Item $it -Root $Root -DownloadsDir $dl
                $done++
                $Sync.Percent = [int](($done / $total) * 100)
                [void]$Sync.Log.Add("Instalado: $($it.Label)")
            }
            $Sync.Status = "Aplicando configuracion..."
            & (Join-Path $Root "lua.ps1") init | Out-Null
            if ($Items.Id -contains 'phpmyadmin') {
                [void]$Sync.Log.Add("Registrando phpMyAdmin como sitio...")
                Register-PhpMyAdminSite -Root $Root
            }
            $Sync.Percent = 100
            [void]$Sync.Log.Add("Configuracion aplicada.")
            $Sync.Status = "Completado."
        } catch {
            $Sync.Error = $_.Exception.Message
            [void]$Sync.Log.Add("ERROR: $($_.Exception.Message)")
        } finally {
            $Sync.Done = $true
        }
    }

    $script:rs = [runspacefactory]::CreateRunspace()
    $script:rs.Open()
    $script:ps = [powershell]::Create()
    $script:ps.Runspace = $script:rs
    [void]$script:ps.AddScript($installScript).AddArgument($Root).AddArgument($script:installItems).AddArgument($script:syncHash)
    $script:asyncResult = $script:ps.BeginInvoke()

    $script:lastLogCount = 0
    $script:timer = New-Object System.Windows.Threading.DispatcherTimer
    $script:timer.Interval = [TimeSpan]::FromMilliseconds(250)
    $script:timer.Add_Tick({
        $pbProgress.Value = $script:syncHash.Percent
        $txtStatus.Text = $script:syncHash.Status
        $cnt = $script:syncHash.Log.Count
        if ($cnt -gt $script:lastLogCount) {
            for ($i = $script:lastLogCount; $i -lt $cnt; $i++) { [void]$lstLog.Items.Add($script:syncHash.Log[$i]) }
            $script:lastLogCount = $cnt
            if ($lstLog.Items.Count -gt 0) { $lstLog.ScrollIntoView($lstLog.Items[$lstLog.Items.Count - 1]) }
        }
        if ($script:syncHash.Done) {
            $script:timer.Stop()
            try { $script:ps.EndInvoke($script:asyncResult) | Out-Null } catch {}
            $script:ps.Dispose(); $script:rs.Close(); $script:rs.Dispose()
            if ($script:syncHash.Error) {
                $txtStatus.Text = "Error: $($script:syncHash.Error)"
                $btnBack.Visibility = 'Visible'
                $btnBack.IsEnabled = $true
                $footer.Visibility = 'Visible'
            } else {
                $labels = ($script:installItems | ForEach-Object { $_.Label }) -join ", "
                $txtDoneSummary.Text = "Se instalo: $labels.`n`nSi Apache o PHP no arrancan por falta de una DLL, usa el boton de abajo para instalar Visual C++ Redistributable."
                Show-Step 'done'
            }
        }
    })
    $script:timer.Start()
}

$btnNext.Add_Click({
    if ($script:step -eq 'welcome') { Show-Step 'components' }
    elseif ($script:step -eq 'components') { Show-Step 'progress'; Start-Install }
})
$btnBack.Add_Click({
    if ($script:step -eq 'components') { Show-Step 'welcome' }
    elseif ($script:step -eq 'progress') { Show-Step 'components' }
})
$btnCancel.Add_Click({
    $r = [System.Windows.MessageBox]::Show($window, 'Seguro que quieres salir del instalador?', 'Cancelar', [System.Windows.MessageBoxButton]::YesNo, [System.Windows.MessageBoxImage]::Question)
    if ($r -eq [System.Windows.MessageBoxResult]::Yes) {
        if ($script:ps) { try { $script:ps.Stop() } catch {} }
        $window.Close()
    }
})
$window.Add_Closing({
    if ($script:ps) { try { $script:ps.Stop() } catch {} }
})

$btnLaunch.Add_Click({
    try { & (Join-Path $Root "lua.ps1") start | Out-Null } catch {}
    Start-Sleep -Milliseconds 800
    Start-Process "http://127.0.0.1/"
    $window.Close()
})
$btnVcRedist.Add_Click({
    $vc = Join-Path $Root "downloads\vc_redist.x64.exe"
    if (Test-Path $vc) { Start-Process $vc } else { [System.Windows.MessageBox]::Show($window, "No se encontro downloads\vc_redist.x64.exe", "Aviso") | Out-Null }
})

Show-Step 'welcome'
$window.ShowDialog() | Out-Null
