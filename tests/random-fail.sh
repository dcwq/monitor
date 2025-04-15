bash -c 'exit $((RANDOM % 2))'
  code=$?
  if [ $code -eq 0 ]; then
    echo "✅ Sukces (kod: $code)"
  else
    echo "❌ Błąd (kod: $code)"
  fi
  sleep 15
