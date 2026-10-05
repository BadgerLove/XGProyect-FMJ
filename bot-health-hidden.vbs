' Pause gate (Dale 2026-10-02): if bots-paused-until.txt holds "YYYY MM DD HH MM"
' and that time is still in the future, do nothing. Delete the file to resume early.
Set fso = CreateObject("Scripting.FileSystemObject")
pauseFile = "C:\Users\Badger\.openclaw\workspace\xgproyect\bots-paused-until.txt"
If fso.FileExists(pauseFile) Then
    p = Split(Trim(fso.OpenTextFile(pauseFile, 1).ReadLine), " ")
    untilAt = DateSerial(CInt(p(0)), CInt(p(1)), CInt(p(2))) + TimeSerial(CInt(p(3)), CInt(p(4)), 0)
    If Now < untilAt Then WScript.Quit 0
End If
Set objShell = CreateObject("WScript.Shell")
objShell.CurrentDirectory = "C:\Users\Badger\.openclaw\workspace\xgproyect"
objShell.Run "C:\Users\Badger\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe artisan bot:health", 0, True
