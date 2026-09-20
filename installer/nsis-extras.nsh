; LinkEasy Publisher — NSIS customisation.
;
; Included from windows-app/package.json -> build.nsis.include.
;
; Goals:
;   * install without an admin prompt by default (per-user), with an optional
;     elevated install into Program Files
;   * never leave a service, scheduled task or auto-start entry behind
;   * never delete the operator's data without asking, because a reinstall
;     should not force a Facebook re-authentication
;   * close a running instance before replacing files

!include "LogicLib.nsh"
!include "MUI2.nsh"

!define LINKEASY_DATA_DIR "$LOCALAPPDATA\LinkEasyPublisher"

Var LinkEasyKillOnExit

; ---------------------------------------------------------------- install

!macro customInit
  ; A running instance would lock chromium binaries and the worker files.
  ExecWait 'taskkill /IM "LinkEasy Publisher.exe" /T /F' $0
  Sleep 400
!macroend

!macro customInstall
  ; Create the per-user data folders up front so the first launch has nothing
  ; to guess and no permission surprises.
  CreateDirectory "${LINKEASY_DATA_DIR}"
  CreateDirectory "${LINKEASY_DATA_DIR}\config"
  CreateDirectory "${LINKEASY_DATA_DIR}\profiles"
  CreateDirectory "${LINKEASY_DATA_DIR}\logs"
  CreateDirectory "${LINKEASY_DATA_DIR}\cache"
  CreateDirectory "${LINKEASY_DATA_DIR}\downloads"
  CreateDirectory "${LINKEASY_DATA_DIR}\screenshots"
  CreateDirectory "${LINKEASY_DATA_DIR}\traces"
  CreateDirectory "${LINKEASY_DATA_DIR}\backup"

  ; Remove any stale auto-start entry left by an older installation; the
  ; application writes its own entry when the operator enables the option.
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "LinkEasy Publisher"

  DetailPrint "LinkEasy Publisher is installed."
  DetailPrint "Your data will live in: ${LINKEASY_DATA_DIR}"
!macroend

; ---------------------------------------------------------------- uninstall

!macro customUnInstall
  ; Stop the app if it is still running.
  ExecWait 'taskkill /IM "LinkEasy Publisher.exe" /T /F' $0

  ; Remove the auto-start entry so an uninstalled app cannot launch at logon.
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "LinkEasy Publisher"
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "com.linkeasy.publisher"

  ; Ask before touching the operator's data: browser profiles represent a
  ; signed-in Facebook session that should not be thrown away casually.
  MessageBox MB_YESNO|MB_ICONQUESTION \
    "Keep your LinkEasy data (browser sessions, logs, settings)?" \
    /SD IDYES IDNO deleteData
  DetailPrint "Keeping ${LINKEASY_DATA_DIR}"
  Goto done

  deleteData:
    RMDir /r "${LINKEASY_DATA_DIR}"
    DetailPrint "Removed ${LINKEASY_DATA_DIR}"

  done:
!macroend

; ---------------------------------------------------------------- finish page

!macro customFinishPage
  ; Offer to start the application straight after installation, since there is
  ; nothing else for the operator to do (prompt §74).
  !define MUI_FINISHPAGE_RUN "$INSTDIR\LinkEasy Publisher.exe"
  !define MUI_FINISHPAGE_RUN_TEXT "Start LinkEasy Publisher"
  !define MUI_FINISHPAGE_TEXT "LinkEasy Publisher is ready.$\r$\n$\r$\nOn first launch, enter your workspace address and sign in — the app installs nothing else and never asks for your Facebook password."
!macroend
