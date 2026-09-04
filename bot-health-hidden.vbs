Set objShell = CreateObject("WScript.Shell")
objShell.CurrentDirectory = "C:\Users\Badger\.openclaw\workspace\xgproyect"
objShell.Run "C:\Users\Badger\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe artisan bot:health", 0, True
